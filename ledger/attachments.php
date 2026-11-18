<?php
declare(strict_types=1);

/**
 * Supporting documents: a photo of a receipt, a contract, a supplier's
 * invoice, attached to a journal entry or to a document (invoice or bill).
 *
 * The core records what the file is and fingerprints it (SHA-256); the host
 * stores the bytes through a LedgerFileStore. Reading a file back checks the
 * fingerprint, so what an auditor opens is provably what was attached. The
 * type is read from the file's own first bytes, never from its name or from
 * what the browser claims.
 */

require_once __DIR__ . '/ledger.php';

const LEDGER_ATTACH_MAX_BYTES = 15 * 1024 * 1024;
const LEDGER_ATTACH_KINDS = ['receipt', 'invoice', 'bill', 'contract', 'statement', 'photo', 'other'];
/** Entries that should carry a document: typed by a person, or money spent without a bill. */
const LEDGER_NEEDS_SUPPORT = ['manual', 'expense.paid', 'asset.purchased'];

/** Where the host keeps the bytes. Keys are write-once. */
interface LedgerFileStore
{
    public function put(string $key, string $bytes, string $contentType): void;
    public function get(string $key): string;
}

/** A directory on disk, for development and tests. Production hosts use S3 with versioning and object lock. */
final class LedgerDirStore implements LedgerFileStore
{
    public function __construct(private string $dir)
    {
    }

    private function path(string $key): string
    {
        if (!preg_match('#^[a-z0-9][a-z0-9/._-]{0,299}$#', $key) || str_contains($key, '..')) {
            throw new LedgerError('That is not a storage key.');
        }
        return rtrim($this->dir, '/') . '/' . $key;
    }

    public function put(string $key, string $bytes, string $contentType): void
    {
        $p = $this->path($key);
        if (is_file($p)) {
            if (hash_file('sha256', $p) !== hash('sha256', $bytes)) {
                throw new LedgerError('A different file is already stored under that key.');
            }
            return;
        }
        if (!is_dir(dirname($p)) && !mkdir(dirname($p), 0700, true) && !is_dir(dirname($p))) {
            throw new RuntimeException('Cannot create the attachment folder.');
        }
        $tmp = $p . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $p)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot store the attachment.');
        }
    }

    public function get(string $key): string
    {
        $p = $this->path($key);
        $b = is_file($p) ? file_get_contents($p) : false;
        if ($b === false) {
            throw new LedgerError('The stored file is missing.');
        }
        return $b;
    }
}

/** What a file really is, from its first bytes. Null when it is not a photo or a PDF. */
function ledger_sniff(string $bytes): ?string
{
    $h = substr($bytes, 0, 16);
    return match (true) {
        str_starts_with($h, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($h, "\x89PNG\r\n\x1A\n") => 'image/png',
        substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP' => 'image/webp',
        substr($h, 4, 4) === 'ftyp' && in_array(substr($h, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'], true) => 'image/heic',
        str_starts_with($h, '%PDF-') => 'application/pdf',
        default => null,
    };
}

/**
 * Attach a file. $a: entry_id or document_id (exactly one), name, bytes,
 * kind (receipt, invoice, bill, contract, statement, photo, other), note,
 * actor. Attaching the same file to the same thing again returns the first
 * attachment. Returns the attachment id.
 */
function ledger_attach(PDO $pdo, int $bookId, array $a, LedgerFileStore $store): int
{
    $entry = isset($a['entry_id']) ? (int) $a['entry_id'] : null;
    $doc = isset($a['document_id']) ? (int) $a['document_id'] : null;
    if (($entry === null) === ($doc === null)) {
        throw new LedgerError('Attach a file to one entry or one document.');
    }
    $bytes = (string) ($a['bytes'] ?? '');
    $size = strlen($bytes);
    if ($size === 0) {
        throw new LedgerError('The file is empty.');
    }
    if ($size > LEDGER_ATTACH_MAX_BYTES) {
        throw new LedgerError('Files can be up to 15 MB.');
    }
    $type = ledger_sniff($bytes);
    if ($type === null) {
        throw new LedgerError('Attach a photo (JPEG, PNG, WebP or HEIC) or a PDF.');
    }
    $kind = (string) ($a['kind'] ?? 'other');
    if (!in_array($kind, LEDGER_ATTACH_KINDS, true)) {
        throw new LedgerError('That is not a kind of document.');
    }
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($a['name'] ?? '')) ?? '');
    $name = mb_substr($name !== '' ? basename(str_replace('\\', '/', $name)) : 'document', 0, 200);
    $sha = hash('sha256', $bytes);

    return ledger_atomic($pdo, function () use ($pdo, $bookId, $entry, $doc, $store, $bytes, $size, $type, $kind, $name, $sha, $a) {
        // The thing it is attached to must be in these books (row-level security hides other tenants' rows).
        $q = $pdo->prepare($entry !== null ? 'SELECT 1 FROM ledger.entries WHERE book_id = :b AND id = :id' : 'SELECT 1 FROM ledger.documents WHERE book_id = :b AND id = :id');
        $q->execute(['b' => $bookId, 'id' => $entry ?? $doc]);
        if (!$q->fetchColumn()) {
            throw new LedgerError($entry !== null ? 'No such entry in these books.' : 'No such document in these books.');
        }
        $have = $pdo->prepare('SELECT id FROM ledger.attachments WHERE book_id = :b AND ' . ($entry !== null ? 'entry_id' : 'document_id') . ' = :id AND sha256 = :s');
        $have->execute(['b' => $bookId, 'id' => $entry ?? $doc, 's' => $sha]);
        if ($id = $have->fetchColumn()) {
            return (int) $id;
        }
        // Content-addressed: the same bytes are stored once per book, under their fingerprint.
        $key = sprintf('books/%d/%s/%s', $bookId, substr($sha, 0, 2), $sha);
        $store->put($key, $bytes, $type);
        $ins = $pdo->prepare('INSERT INTO ledger.attachments (book_id, entry_id, document_id, kind, name, content_type, size_bytes, sha256, storage_key, note, actor)
                              VALUES (:b, :e, :d, :k, :n, :t, :s, :h, :key, :note, :actor) RETURNING id');
        $ins->execute(['b' => $bookId, 'e' => $entry, 'd' => $doc, 'k' => $kind, 'n' => $name, 't' => $type, 's' => $size, 'h' => $sha, 'key' => $key,
                       'note' => mb_substr(trim((string) ($a['note'] ?? '')), 0, 300), 'actor' => mb_substr((string) ($a['actor'] ?? ''), 0, 120)]);
        return (int) $ins->fetchColumn();
    });
}

/** The attachments on an entry, or on a document. */
function ledger_attachments(PDO $pdo, int $bookId, ?int $entryId = null, ?int $documentId = null): array
{
    if (($entryId === null) === ($documentId === null)) {
        throw new LedgerError('Ask for one entry or one document.');
    }
    $q = $pdo->prepare('SELECT id, entry_id, document_id, kind, name, content_type, size_bytes, sha256, note, actor, created_at
                          FROM ledger.attachments WHERE book_id = :b AND ' . ($entryId !== null ? 'entry_id' : 'document_id') . ' = :id ORDER BY id');
    $q->execute(['b' => $bookId, 'id' => $entryId ?? $documentId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Read an attachment back: its record and its bytes. Refuses when the stored
 * bytes no longer match the fingerprint taken when it was attached.
 */
function ledger_attachment_read(PDO $pdo, int $bookId, int $id, LedgerFileStore $store): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.attachments WHERE book_id = :b AND id = :id');
    $q->execute(['b' => $bookId, 'id' => $id]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        throw new LedgerError('No such attachment in these books.');
    }
    $bytes = $store->get((string) $a['storage_key']);
    if (!hash_equals((string) $a['sha256'], hash('sha256', $bytes))) {
        throw new LedgerError('This file has changed since it was attached; it is not shown. The fingerprint on record is ' . $a['sha256'] . '.');
    }
    return ['attachment' => $a, 'bytes' => $bytes];
}

/**
 * Entries that should carry a document and do not: typed by a person, cash
 * spent without a bill, equipment bought. A document on the invoice or bill an
 * entry issued counts. Reversed entries and reversals are left out.
 */
function ledger_unsupported_entries(PDO $pdo, int $bookId, ?string $from = null, ?string $through = null, array $types = LEDGER_NEEDS_SUPPORT): array
{
    $q = $pdo->prepare("SELECT e.id, e.entry_on, e.event_type, e.memo, e.actor,
                               (SELECT coalesce(sum(l.debit_cents), 0) FROM ledger.lines l WHERE l.entry_id = e.id) AS amount_cents
                          FROM ledger.entries e
                         WHERE e.book_id = :b AND e.event_type = ANY (:t) AND e.reverses_id IS NULL
                           AND NOT EXISTS (SELECT 1 FROM ledger.entries r WHERE r.reverses_id = e.id)
                           AND (CAST(:f AS date) IS NULL OR e.entry_on >= CAST(:f2 AS date))
                           AND (CAST(:th AS date) IS NULL OR e.entry_on <= CAST(:th2 AS date))
                           AND NOT EXISTS (SELECT 1 FROM ledger.attachments a WHERE a.entry_id = e.id)
                           AND NOT EXISTS (SELECT 1 FROM ledger.documents d JOIN ledger.attachments a ON a.document_id = d.id
                                            WHERE d.book_id = e.book_id AND d.issue_entry_id = e.id)
                         ORDER BY e.entry_on, e.id");
    $q->execute(['b' => $bookId, 't' => '{' . implode(',', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"', $types)) . '}',
                 'f' => $from, 'f2' => $from, 'th' => $through, 'th2' => $through]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
