<?php
declare(strict_types=1);

/**
 * STRATICUS accounting core: the engine.
 *
 * Product-agnostic. Every function takes the PDO to work on and a book id;
 * nothing here knows about the host product, its tenants or its users. A
 * host binds tenancy in the database (see sql/0001_ledger.sql) and posts
 * business events through a pack of posting rules.
 *
 * Money is integer cents everywhere. Every posting is idempotent on its
 * source key: posting the same event twice returns the first result.
 *
 * Bases. Each entry is 'both', 'accrual' or 'cash'. A receivable's revenue
 * is an accrual entry; a payment on it posts an accrual entry (cash against
 * receivables) and a cash entry (cash against the document lines it was
 * assigned to, or against unapplied receipts until it is assigned). Reports
 * on a basis read that basis plus 'both', and each basis balances alone.
 */

require_once __DIR__ . '/templates.php';

const LEDGER_SQL_DIR = __DIR__ . '/sql';

class LedgerError extends RuntimeException
{
}

/* ── Migrations ──────────────────────────────────────────────────────── */

/**
 * Apply the core's schema migrations, as the schema owner. {{APP_ROLE}} is
 * replaced with the role the application connects as. Returns the names
 * applied. A host calls this before its own migrations.
 */
function ledger_migrate(PDO $owner, string $appRole): array
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $appRole)) {
        throw new InvalidArgumentException('The app role must be a plain identifier.');
    }
    $owner->exec('CREATE TABLE IF NOT EXISTS ledger_schema_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL)');
    $done  = array_flip($owner->query('SELECT name FROM ledger_schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $files = glob(LEDGER_SQL_DIR . '/*.sql') ?: [];
    sort($files);
    $ran = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (isset($done[$name])) {
            continue;
        }
        $owner->beginTransaction();
        try {
            $owner->exec(str_replace('{{APP_ROLE}}', $appRole, (string) file_get_contents($path)));
            $owner->prepare('INSERT INTO ledger_schema_migrations (name, applied_at) VALUES (:n, now())')->execute(['n' => $name]);
            $owner->commit();
        } catch (Throwable $e) {
            $owner->rollBack();
            throw new RuntimeException("ledger migration $name failed: " . $e->getMessage(), 0, $e);
        }
        $ran[] = $name;
    }
    return $ran;
}

/* ── Transactions ────────────────────────────────────────────────────── */

/**
 * Run $fn atomically: its own transaction, or a savepoint inside the
 * caller's. The balance checks are deferred to COMMIT, so a host that wraps
 * several postings in its own transaction gets them checked when it commits.
 */
function ledger_atomic(PDO $pdo, callable $fn): mixed
{
    static $depth = 0;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw ledger_error($e);
        }
    }
    $sp = 'ledger_sp_' . (++$depth);
    $pdo->exec("SAVEPOINT $sp");
    try {
        $r = $fn();
        $pdo->exec("RELEASE SAVEPOINT $sp");
        return $r;
    } catch (Throwable $e) {
        $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
        throw ledger_error($e);
    } finally {
        $depth--;
    }
}

/** A database refusal as a LedgerError carrying the guard's own words. */
function ledger_error(Throwable $e): Throwable
{
    if ($e instanceof PDOException && preg_match('/ERROR:\s+(.*?)(\n|CONTEXT:|$)/s', $e->getMessage(), $m)) {
        return new LedgerError(trim($m[1]), 0, $e);
    }
    return $e;
}

/* ── Books and accounts ─────────────────────────────────────────────── */

/**
 * Open a set of books with the core chart and the equity section for its
 * tax treatment. Packs add their accounts afterwards.
 */
function ledger_book_create(PDO $pdo, int $tenantId, string $name, string $form = 'unspecified', string $treatment = 'unspecified'): int
{
    ledger_check_entity($form, $treatment);
    return ledger_atomic($pdo, function () use ($pdo, $tenantId, $name, $form, $treatment) {
        $q = $pdo->prepare('INSERT INTO ledger.books (tenant_id, name, entity_form, tax_treatment) VALUES (:t, :n, :f, :x) RETURNING id');
        $q->execute(['t' => $tenantId, 'n' => mb_substr(trim($name) ?: 'Books', 0, 160), 'f' => $form, 'x' => $treatment]);
        $bookId = (int) $q->fetchColumn();
        foreach (array_merge(ledger_template_core(), ledger_template_equity($treatment)) as $spec) {
            ledger_account_ensure($pdo, $bookId, $spec);
        }
        return $bookId;
    });
}

function ledger_check_entity(string $form, string $treatment): void
{
    if (!isset(LEDGER_FORM_TREATMENTS[$form])) {
        throw new LedgerError('Unknown legal form.');
    }
    if (!in_array($treatment, LEDGER_FORM_TREATMENTS[$form], true)) {
        throw new LedgerError('That legal form cannot be taxed that way.');
    }
}

/**
 * Set the legal form and tax treatment: adds the equity accounts the new
 * treatment needs, renames shared ones and closes the ones it does not use
 * to new postings. Balances are not moved here: a change of treatment takes
 * an effective date and a closing entry that moves the equity balances.
 */
function ledger_book_set_entity(PDO $pdo, int $bookId, string $form, string $treatment): void
{
    ledger_check_entity($form, $treatment);
    ledger_atomic($pdo, function () use ($pdo, $bookId, $form, $treatment) {
        $pdo->prepare('UPDATE ledger.books SET entity_form = :f, tax_treatment = :x WHERE id = :b')
            ->execute(['f' => $form, 'x' => $treatment, 'b' => $bookId]);
        $want = [];
        foreach (ledger_template_equity($treatment) as $spec) {
            $want[$spec[5]['key']] = true;
            $id = ledger_account_ensure($pdo, $bookId, $spec);
            $pdo->prepare('UPDATE ledger.accounts SET name = :n, statement_line = :s, active = true WHERE id = :id')
                ->execute(['n' => $spec[1], 's' => $spec[3], 'id' => $id]);
        }
        $q = $pdo->prepare("SELECT id, system_key FROM ledger.accounts WHERE book_id = :b AND type = 'equity'
                             AND system_key LIKE 'equity.%' AND system_key NOT IN ('equity.retained', 'equity.opening')");
        $q->execute(['b' => $bookId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!isset($want[$a['system_key']])) {
                $pdo->prepare('UPDATE ledger.accounts SET active = false WHERE id = :id')->execute(['id' => $a['id']]);
            }
        }
    });
}

/**
 * An account by its system key, made if missing. Codes that are taken move
 * to the next free code in the same hundred. Returns the account id.
 *
 * @param array $spec [code, name, type, statement_line, cash_flow_class, options]
 */
function ledger_account_ensure(PDO $pdo, int $bookId, array $spec): int
{
    [$code, $name, $type, $line, $cf, $opt] = $spec + [5 => []];
    $key = $opt['key'] ?? null;
    if ($key !== null) {
        $q = $pdo->prepare('SELECT id FROM ledger.accounts WHERE book_id = :b AND system_key = :k');
        $q->execute(['b' => $bookId, 'k' => $key]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
    }
    ledger_cache_clear();
    $code = ledger_free_code($pdo, $bookId, (string) $code);
    $q = $pdo->prepare('INSERT INTO ledger.accounts (book_id, code, name, type, contra, statement_line, cash_flow_class, equity_type,
                                                     owner_party, accrual_only, control, system_key)
                        VALUES (:b, :c, :n, :t, :ct, :sl, :cf, :et, :op, :ao, :ctl, :k) RETURNING id');
    $q->execute([
        'b' => $bookId, 'c' => $code, 'n' => mb_substr($name, 0, 120), 't' => $type,
        'ct' => !empty($opt['contra']) ? 't' : 'f', 'sl' => $line, 'cf' => $cf,
        'et' => $opt['equity_type'] ?? null, 'op' => $opt['owner_party'] ?? '',
        'ao' => !empty($opt['accrual_only']) ? 't' : 'f', 'ctl' => $opt['control'] ?? 'none', 'k' => $key,
    ]);
    return (int) $q->fetchColumn();
}

function ledger_free_code(PDO $pdo, int $bookId, string $code): string
{
    $q = $pdo->prepare('SELECT code FROM ledger.accounts WHERE book_id = :b');
    $q->execute(['b' => $bookId]);
    $taken = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    if (!isset($taken[$code])) {
        return $code;
    }
    if (ctype_digit($code)) {
        $n = (int) $code;
        $top = intdiv($n, 100) * 100 + 99;
        for ($i = $n + 1; $i <= $top; $i++) {
            if (!isset($taken[(string) $i])) {
                return (string) $i;
            }
        }
    }
    for ($i = 2; $i < 1000; $i++) {
        if (!isset($taken["$code-$i"])) {
            return "$code-$i";
        }
    }
    throw new LedgerError('No free account code near ' . $code . '.');
}

/** Account id by system key, or a LedgerError. */
function ledger_account_id(PDO $pdo, int $bookId, string $key): int
{
    static $cache = [];
    $ck = spl_object_id($pdo) . ":$bookId:$key";
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $q = $pdo->prepare('SELECT id FROM ledger.accounts WHERE book_id = :b AND system_key = :k');
    $q->execute(['b' => $bookId, 'k' => $key]);
    $id = $q->fetchColumn();
    if ($id === false) {
        throw new LedgerError("No account '$key' in these books.");
    }
    return $cache[$ck] = (int) $id;
}

/** An account reference: an id, or a system key. */
function ledger_account_ref(PDO $pdo, int $bookId, int|string $ref): int
{
    return is_int($ref) ? $ref : ledger_account_id($pdo, $bookId, $ref);
}

/* ── Posting ─────────────────────────────────────────────────────────── */

/**
 * Post one balanced entry. Idempotent on source_key: a second call with the
 * same key returns the first entry's id without posting.
 *
 * $e: entry_on (Y-m-d), lines (each: account (id or system key), debit or
 * credit (cents > 0), party, document_line_id, memo), and optionally basis,
 * source_key, event_type, rule, rule_version, memo, actor, reverses_id.
 *
 * A date inside a locked period moves to the first open day; the date asked
 * for is kept as original_on.
 */
function ledger_post(PDO $pdo, int $bookId, array $e): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $e) {
        if (!empty($e['source_key'])) {
            $q = $pdo->prepare('SELECT id FROM ledger.entries WHERE book_id = :b AND source_key = :k');
            $q->execute(['b' => $bookId, 'k' => $e['source_key']]);
            if (($id = $q->fetchColumn()) !== false) {
                return (int) $id;
            }
        }
        $on = (string) ($e['entry_on'] ?? '');
        if (!ledger_is_date($on)) {
            throw new LedgerError('An entry needs a date.');
        }
        $lines = ledger_normalise_lines($pdo, $bookId, $e['lines'] ?? []);
        [$on, $original] = ledger_open_date($pdo, $bookId, $on);
        $q = $pdo->prepare('INSERT INTO ledger.entries (book_id, entry_on, basis, source_key, event_type, rule, rule_version, memo, reverses_id, original_on, actor)
                            VALUES (:b, :on, :basis, :sk, :et, :r, :rv, :m, :rev, :orig, :a) RETURNING id');
        $q->execute([
            'b' => $bookId, 'on' => $on, 'basis' => $e['basis'] ?? 'both', 'sk' => $e['source_key'] ?? null,
            'et' => $e['event_type'] ?? 'manual', 'r' => $e['rule'] ?? '', 'rv' => (int) ($e['rule_version'] ?? 0),
            'm' => mb_substr((string) ($e['memo'] ?? ''), 0, 500), 'rev' => $e['reverses_id'] ?? null,
            'orig' => $original, 'a' => mb_substr((string) ($e['actor'] ?? ''), 0, 120),
        ]);
        $entryId = (int) $q->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO ledger.lines (book_id, entry_id, account_id, debit_cents, credit_cents, party, document_line_id, memo)
                              VALUES (:b, :e, :a, :d, :c, :p, :dl, :m)');
        foreach ($lines as $l) {
            $ins->execute(['b' => $bookId, 'e' => $entryId] + $l);
        }
        return $entryId;
    });
}

/**
 * Lines as the database takes them: account ids, positive debit or credit,
 * zero lines dropped, and the entry checked for balance before it is sent
 * (the database checks again at commit).
 */
function ledger_normalise_lines(PDO $pdo, int $bookId, array $lines): array
{
    $out = [];
    $d = $c = 0;
    foreach ($lines as $l) {
        $debit  = (int) ($l['debit'] ?? 0);
        $credit = (int) ($l['credit'] ?? 0);
        if ($debit < 0) {
            [$debit, $credit] = [0, $credit - $debit];
        }
        if ($credit < 0) {
            [$debit, $credit] = [$debit - $credit, 0];
        }
        if ($debit > 0 && $credit > 0) {
            // One account, both sides: net it.
            [$debit, $credit] = $debit >= $credit ? [$debit - $credit, 0] : [0, $credit - $debit];
        }
        if ($debit === 0 && $credit === 0) {
            continue;
        }
        $d += $debit;
        $c += $credit;
        $out[] = [
            'a'  => ledger_account_ref($pdo, $bookId, $l['account']),
            'd'  => $debit,
            'c'  => $credit,
            'p'  => mb_substr((string) ($l['party'] ?? ''), 0, 120),
            'dl' => $l['document_line_id'] ?? null,
            'm'  => mb_substr((string) ($l['memo'] ?? ''), 0, 300),
        ];
    }
    if (count($out) < 2) {
        throw new LedgerError('An entry needs at least two lines.');
    }
    if ($d !== $c) {
        throw new LedgerError(sprintf('That entry does not balance: debits %d cents, credits %d cents.', $d, $c));
    }
    return $out;
}

/** [date to post on, the date asked for if it moved]. */
function ledger_open_date(PDO $pdo, int $bookId, string $on): array
{
    $q = $pdo->prepare('SELECT locked_through FROM ledger.books WHERE id = :b');
    $q->execute(['b' => $bookId]);
    $locked = $q->fetchColumn();
    if ($locked !== false && $locked !== null && $on <= $locked) {
        return [gmdate('Y-m-d', strtotime($locked . ' +1 day')), $on];
    }
    return [$on, null];
}

function ledger_is_date(string $d): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Reverse an entry: the same lines, sides swapped, same basis. Idempotent
 * on $sourceKey. Returns the reversing entry's id.
 */
function ledger_reverse(PDO $pdo, int $bookId, int $entryId, string $sourceKey, string $on, string $actor, string $memo): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $entryId, $sourceKey, $on, $actor, $memo) {
        $q = $pdo->prepare('SELECT id FROM ledger.entries WHERE book_id = :b AND (source_key = :k OR reverses_id = :e)');
        $q->execute(['b' => $bookId, 'k' => $sourceKey, 'e' => $entryId]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
        $q = $pdo->prepare('SELECT * FROM ledger.entries WHERE book_id = :b AND id = :e');
        $q->execute(['b' => $bookId, 'e' => $entryId]);
        $orig = $q->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            throw new LedgerError('No such entry in these books.');
        }
        $l = $pdo->prepare('SELECT account_id, debit_cents, credit_cents, party, document_line_id, memo FROM ledger.lines WHERE entry_id = :e ORDER BY id');
        $l->execute(['e' => $entryId]);
        $lines = [];
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = ['account' => (int) $r['account_id'], 'debit' => (int) $r['credit_cents'], 'credit' => (int) $r['debit_cents'],
                        'party' => $r['party'], 'document_line_id' => $r['document_line_id'], 'memo' => $r['memo']];
        }
        return ledger_post($pdo, $bookId, [
            'entry_on' => max($on, (string) $orig['entry_on']), 'basis' => $orig['basis'], 'source_key' => $sourceKey,
            'event_type' => $orig['event_type'], 'rule' => $orig['rule'], 'rule_version' => (int) $orig['rule_version'],
            'memo' => $memo, 'actor' => $actor, 'reverses_id' => $entryId, 'lines' => $lines,
        ]);
    });
}

/* ── Receivables and payables ───────────────────────────────────────── */

/**
 * Issue a document (an invoice to a customer, or a bill from a vendor) and
 * post it on the accrual basis. Idempotent on source_key.
 *
 * $d: kind ('receivable'|'payable'), party, ref, issued_on, source_key,
 * actor, control (system key or id; default 'ar' / 'ap'), lines (each:
 * account, amount (cents, may be negative), memo, source_ref).
 * Returns the document id.
 */
function ledger_document_issue(PDO $pdo, int $bookId, array $d): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $d) {
        $q = $pdo->prepare('SELECT id FROM ledger.documents WHERE book_id = :b AND source_key = :k');
        $q->execute(['b' => $bookId, 'k' => $d['source_key']]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
        $kind = $d['kind'] ?? 'receivable';
        $control = ledger_account_ref($pdo, $bookId, $d['control'] ?? ($kind === 'receivable' ? 'ar' : 'ap'));
        $lines = array_values(array_filter($d['lines'] ?? [], fn($l) => (int) $l['amount'] !== 0));
        $total = array_sum(array_map(fn($l) => (int) $l['amount'], $lines));
        if ($total < 0) {
            throw new LedgerError('A document cannot total less than zero; record a credit instead.');
        }
        if (!ledger_is_date((string) ($d['issued_on'] ?? ''))) {
            throw new LedgerError('A document needs a date.');
        }
        $due = (string) ($d['due_on'] ?? '');
        $q = $pdo->prepare('INSERT INTO ledger.documents (book_id, kind, party, ref, issued_on, due_on, total_cents, control_account_id, source_key)
                            VALUES (:b, :k, :p, :r, :on, :due, :t, :c, :sk) RETURNING id');
        $q->execute(['b' => $bookId, 'k' => $kind, 'p' => $d['party'], 'r' => mb_substr((string) ($d['ref'] ?? ''), 0, 80),
                     'on' => $d['issued_on'], 'due' => ledger_is_date($due) ? $due : null, 't' => $total, 'c' => $control, 'sk' => $d['source_key']]);
        $docId = (int) $q->fetchColumn();
        if ($total === 0) {
            return $docId;   // nothing owed: on record, nothing to post
        }
        $li = $pdo->prepare('INSERT INTO ledger.document_lines (book_id, document_id, account_id, amount_cents, memo, source_ref)
                             VALUES (:b, :d, :a, :amt, :m, :s) RETURNING id');
        $entryLines = [];
        // Receivable: debit the control, credit each line. Payable: the other way.
        $sign = $kind === 'receivable' ? 1 : -1;
        $entryLines[] = ['account' => $control, 'debit' => $sign * $total, 'party' => $d['party']];
        foreach ($lines as $l) {
            $acct = ledger_account_ref($pdo, $bookId, $l['account']);
            $li->execute(['b' => $bookId, 'd' => $docId, 'a' => $acct, 'amt' => (int) $l['amount'],
                          'm' => mb_substr((string) ($l['memo'] ?? ''), 0, 300), 's' => mb_substr((string) ($l['source_ref'] ?? ''), 0, 120)]);
            $dlId = (int) $li->fetchColumn();
            $entryLines[] = ['account' => $acct, 'credit' => $sign * (int) $l['amount'], 'party' => $d['party'],
                             'document_line_id' => $dlId, 'memo' => $l['memo'] ?? ''];
        }
        $entryId = ledger_post($pdo, $bookId, [
            'entry_on' => $d['issued_on'], 'basis' => 'accrual', 'source_key' => $d['source_key'] . '#issue',
            'event_type' => $kind === 'receivable' ? 'receivable.issued' : 'payable.issued', 'rule' => $d['rule'] ?? 'core.document',
            'rule_version' => (int) ($d['rule_version'] ?? 1), 'memo' => $d['memo'] ?? ($d['ref'] ?? ''), 'actor' => $d['actor'] ?? '',
            'lines' => $entryLines,
        ]);
        $pdo->prepare('UPDATE ledger.documents SET issue_entry_id = :e WHERE id = :d')->execute(['e' => $entryId, 'd' => $docId]);
        return $docId;
    });
}

/** A document row, locked for this transaction. */
function ledger_document_lock(PDO $pdo, int $bookId, int $docId): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.documents WHERE book_id = :b AND id = :d FOR UPDATE');
    $q->execute(['b' => $bookId, 'd' => $docId]);
    $doc = $q->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        throw new LedgerError('No such document in these books.');
    }
    return $doc;
}

/** What is still owed on a document: total less live payments and write-offs. */
function ledger_document_open(PDO $pdo, int $docId): int
{
    $q = $pdo->prepare('SELECT d.total_cents
                              - coalesce((SELECT SUM(amount_cents) FROM ledger.settlements WHERE document_id = d.id AND reversed_at IS NULL), 0)
                              - coalesce((SELECT SUM(amount_cents) FROM ledger.write_offs WHERE document_id = d.id), 0)
                          FROM ledger.documents d WHERE d.id = :d');
    $q->execute(['d' => $docId]);
    return (int) $q->fetchColumn();
}

/**
 * Each line of a document with what live payments have already assigned to
 * it and what remains: [id, account_id, amount, assigned, remaining, memo].
 */
function ledger_document_lines(PDO $pdo, int $docId): array
{
    $q = $pdo->prepare('SELECT dl.id, dl.account_id, dl.amount_cents, dl.memo, dl.source_ref,
                               coalesce((SELECT SUM(a.amount_cents) FROM ledger.allocations a
                                           JOIN ledger.settlements s ON s.id = a.settlement_id AND s.reversed_at IS NULL
                                          WHERE a.document_line_id = dl.id), 0) AS assigned
                          FROM ledger.document_lines dl WHERE dl.document_id = :d ORDER BY dl.id');
    $q->execute(['d' => $docId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'account_id' => (int) $r['account_id'], 'amount' => (int) $r['amount_cents'],
            'assigned' => (int) $r['assigned'], 'remaining' => (int) $r['amount_cents'] - (int) $r['assigned'],
            'memo' => $r['memo'], 'source_ref' => $r['source_ref'],
        ];
    }
    return $out;
}

/** Void a document with nothing paid on it: its issue entry is reversed. */
function ledger_document_void(PDO $pdo, int $bookId, int $docId, string $on, string $actor, string $memo = ''): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $docId, $on, $actor, $memo) {
        $doc = ledger_document_lock($pdo, $bookId, $docId);
        if ($doc['status'] === 'void') {
            return;
        }
        $voidEntry = null;
        if ($doc['issue_entry_id'] !== null) {
            $voidEntry = ledger_reverse($pdo, $bookId, (int) $doc['issue_entry_id'], $doc['source_key'] . '#void', $on, $actor,
                $memo !== '' ? $memo : 'Void ' . $doc['ref']);
        }
        $pdo->prepare("UPDATE ledger.documents SET status = 'void', void_entry_id = :e WHERE id = :d")
            ->execute(['e' => $voidEntry, 'd' => $docId]);
    });
}

/**
 * Record a payment against a document. Idempotent on source_key.
 *
 * $s: settled_on, amount (cents > 0), cash_account (system key or id),
 * source_key, actor, allocations (optional: document line id => cents).
 *
 * Accrual: cash against the control account. Cash view: cash against the
 * lines the payment was assigned to. A payment that clears the document is
 * assigned to every line's remainder automatically; a part payment with no
 * assignment waits in unapplied receipts (cash view only) until someone
 * assigns it with ledger_settlement_assign().
 *
 * Returns the settlement id.
 */
function ledger_settle(PDO $pdo, int $bookId, int $docId, array $s): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $docId, $s) {
        $q = $pdo->prepare('SELECT id FROM ledger.settlements WHERE book_id = :b AND source_key = :k');
        $q->execute(['b' => $bookId, 'k' => $s['source_key']]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
        $doc = ledger_document_lock($pdo, $bookId, $docId);
        if ($doc['status'] !== 'open') {
            throw new LedgerError('That document is void.');
        }
        $amt = (int) ($s['amount'] ?? 0);
        $open = ledger_document_open($pdo, $docId);
        if ($amt <= 0) {
            throw new LedgerError('A payment needs an amount.');
        }
        if ($amt > $open) {
            throw new LedgerError(sprintf('That payment is more than the %d cents still owed.', $open));
        }
        $on = (string) ($s['settled_on'] ?? '');
        if (!ledger_is_date($on)) {
            throw new LedgerError('A payment needs a date.');
        }
        $cash = ledger_account_ref($pdo, $bookId, $s['cash_account'] ?? 'cash.operating');
        $alloc = $s['allocations'] ?? null;
        if ($alloc === null && $amt === $open && !ledger_has_write_offs($pdo, $docId)) {
            // It clears the document: each line takes what it still owes, but
            // only when earlier payments are all assigned (otherwise the lines'
            // remainders add up to more than this payment, and it waits too).
            $rest = ledger_allocate_remainder($pdo, $docId);
            if (array_sum($rest) === $amt) {
                $alloc = $rest;
            }
        }
        if ($alloc !== null) {
            $alloc = ledger_check_allocation($pdo, $docId, $amt, $alloc);
        }
        $q = $pdo->prepare('INSERT INTO ledger.settlements (book_id, document_id, settled_on, amount_cents, cash_account_id, source_key)
                            VALUES (:b, :d, :on, :a, :c, :sk) RETURNING id');
        $q->execute(['b' => $bookId, 'd' => $docId, 'on' => $on, 'a' => $amt, 'c' => $cash, 'sk' => $s['source_key']]);
        $sid = (int) $q->fetchColumn();
        $actor = (string) ($s['actor'] ?? '');
        $recv = $doc['kind'] === 'receivable';
        $sign = $recv ? 1 : -1;   // a receivable brings cash in; a payable sends it out

        $cashParty = (string) ($s['cash_party'] ?? '');   // when the money comes from a party's own credit, not a bank
        $accrual = ledger_post($pdo, $bookId, [
            'entry_on' => $on, 'basis' => 'accrual', 'source_key' => $s['source_key'] . '#accrual',
            'event_type' => $recv ? 'receivable.paid' : 'payable.paid', 'rule' => 'core.settle', 'rule_version' => 1,
            'memo' => $s['memo'] ?? ('Payment on ' . $doc['ref']), 'actor' => $actor,
            'lines' => [
                ['account' => $cash, 'debit' => $sign * $amt, 'party' => $cashParty],
                ['account' => (int) $doc['control_account_id'], 'credit' => $sign * $amt, 'party' => $doc['party']],
            ],
        ]);
        $cashLines = [['account' => $cash, 'debit' => $sign * $amt, 'party' => $cashParty]];
        if ($alloc === null) {
            $cashLines[] = ['account' => 'unapplied_receipts', 'credit' => $sign * $amt, 'memo' => 'Awaiting assignment to lines'];
        } else {
            $cashLines = array_merge($cashLines, ledger_allocation_lines($pdo, $docId, $alloc, $sign, $doc['party']));
            ledger_store_allocations($pdo, $bookId, $sid, $alloc, $actor);
        }
        $cashEntry = ledger_post($pdo, $bookId, [
            'entry_on' => $on, 'basis' => 'cash', 'source_key' => $s['source_key'] . '#cash',
            'event_type' => $recv ? 'receivable.paid' : 'payable.paid', 'rule' => 'core.settle', 'rule_version' => 1,
            'memo' => $s['memo'] ?? ('Payment on ' . $doc['ref']), 'actor' => $actor, 'lines' => $cashLines,
        ]);
        $pdo->prepare('UPDATE ledger.settlements SET accrual_entry_id = :a, cash_entry_id = :c WHERE id = :s')
            ->execute(['a' => $accrual, 'c' => $cashEntry, 's' => $sid]);
        return $sid;
    });
}

function ledger_has_write_offs(PDO $pdo, int $docId): bool
{
    $q = $pdo->prepare('SELECT 1 FROM ledger.write_offs WHERE document_id = :d LIMIT 1');
    $q->execute(['d' => $docId]);
    return (bool) $q->fetchColumn();
}

/** Every line's remainder: what a payment that clears the document assigns. */
function ledger_allocate_remainder(PDO $pdo, int $docId): array
{
    $out = [];
    foreach (ledger_document_lines($pdo, $docId) as $l) {
        if ($l['remaining'] !== 0) {
            $out[$l['id']] = $l['remaining'];
        }
    }
    return $out;
}

/**
 * Check an assignment: lines of this document only, each within what the
 * line still owes (negative lines between their remainder and zero), adding
 * up to the payment exactly. Returns the cleaned map.
 */
function ledger_check_allocation(PDO $pdo, int $docId, int $amount, array $alloc): array
{
    $lines = [];
    foreach (ledger_document_lines($pdo, $docId) as $l) {
        $lines[$l['id']] = $l;
    }
    $clean = [];
    $sum = 0;
    foreach ($alloc as $lineId => $cents) {
        $lineId = (int) $lineId;
        $cents = (int) $cents;
        if ($cents === 0) {
            continue;
        }
        if (!isset($lines[$lineId])) {
            throw new LedgerError('A payment is assigned only to lines of its own document.');
        }
        $rem = $lines[$lineId]['remaining'];
        $ok = $rem >= 0 ? ($cents > 0 && $cents <= $rem) : ($cents < 0 && $cents >= $rem);
        if (!$ok) {
            throw new LedgerError(sprintf('Line "%s" can take at most %d cents of this payment.', $lines[$lineId]['memo'] ?: $lineId, $rem));
        }
        $clean[$lineId] = $cents;
        $sum += $cents;
    }
    if ($sum !== $amount) {
        throw new LedgerError(sprintf('The assignment adds up to %d cents; the payment is %d cents.', $sum, $amount));
    }
    return $clean;
}

/** Cash-view lines for an assignment: each line's account takes its share. */
function ledger_allocation_lines(PDO $pdo, int $docId, array $alloc, int $sign, string $party): array
{
    $acct = [];
    foreach (ledger_document_lines($pdo, $docId) as $l) {
        $acct[$l['id']] = $l;
    }
    $out = [];
    foreach ($alloc as $lineId => $cents) {
        $out[] = ['account' => $acct[$lineId]['account_id'], 'credit' => $sign * $cents, 'party' => $party,
                  'document_line_id' => $lineId, 'memo' => $acct[$lineId]['memo']];
    }
    return $out;
}

function ledger_store_allocations(PDO $pdo, int $bookId, int $settlementId, array $alloc, string $actor): void
{
    $ins = $pdo->prepare('INSERT INTO ledger.allocations (book_id, settlement_id, document_line_id, amount_cents, actor) VALUES (:b, :s, :l, :a, :who)');
    foreach ($alloc as $lineId => $cents) {
        $ins->execute(['b' => $bookId, 's' => $settlementId, 'l' => $lineId, 'a' => $cents, 'who' => mb_substr($actor, 0, 120)]);
    }
}

/** Payments recorded without an assignment, still waiting for one. */
function ledger_unassigned(PDO $pdo, int $bookId): array
{
    $q = $pdo->prepare('SELECT s.*, d.ref, d.party FROM ledger.settlements s JOIN ledger.documents d ON d.id = s.document_id
                         WHERE s.book_id = :b AND s.reversed_at IS NULL AND s.assign_entry_id IS NULL
                           AND NOT EXISTS (SELECT 1 FROM ledger.allocations a WHERE a.settlement_id = s.id)
                         ORDER BY s.settled_on, s.id');
    $q->execute(['b' => $bookId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Assign a waiting payment to its document's lines. The cash view moves it
 * from unapplied receipts onto the lines, dated the day it was paid (or the
 * first open day, if that period is locked).
 */
function ledger_settlement_assign(PDO $pdo, int $bookId, int $settlementId, array $alloc, string $actor): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $settlementId, $alloc, $actor) {
        $q = $pdo->prepare('SELECT * FROM ledger.settlements WHERE book_id = :b AND id = :s FOR UPDATE');
        $q->execute(['b' => $bookId, 's' => $settlementId]);
        $s = $q->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            throw new LedgerError('No such payment in these books.');
        }
        if ($s['reversed_at'] !== null) {
            throw new LedgerError('That payment was reversed.');
        }
        $has = $pdo->prepare('SELECT 1 FROM ledger.allocations WHERE settlement_id = :s LIMIT 1');
        $has->execute(['s' => $settlementId]);
        if ($has->fetchColumn()) {
            throw new LedgerError('That payment is already assigned. Reverse it and record it again to change the assignment.');
        }
        $doc = ledger_document_lock($pdo, $bookId, (int) $s['document_id']);
        $alloc = ledger_check_allocation($pdo, (int) $doc['id'], (int) $s['amount_cents'], $alloc);
        $sign = $doc['kind'] === 'receivable' ? 1 : -1;
        $lines = array_merge(
            [['account' => 'unapplied_receipts', 'debit' => $sign * (int) $s['amount_cents'], 'memo' => 'Assigned to lines']],
            ledger_allocation_lines($pdo, (int) $doc['id'], $alloc, $sign, $doc['party'])
        );
        ledger_store_allocations($pdo, $bookId, $settlementId, $alloc, $actor);
        $entry = ledger_post($pdo, $bookId, [
            'entry_on' => $s['settled_on'], 'basis' => 'cash', 'source_key' => $s['source_key'] . '#assign',
            'event_type' => 'payment.assigned', 'rule' => 'core.assign', 'rule_version' => 1,
            'memo' => 'Payment on ' . $doc['ref'] . ' assigned to lines', 'actor' => $actor, 'lines' => $lines,
        ]);
        $pdo->prepare('UPDATE ledger.settlements SET assign_entry_id = :e WHERE id = :s')->execute(['e' => $entry, 's' => $settlementId]);
    });
}

/**
 * Reverse a payment recorded in error: its accrual, cash and assignment
 * entries are each reversed, and the document owes the amount again.
 */
function ledger_settlement_reverse(PDO $pdo, int $bookId, int $settlementId, string $on, string $actor, string $reason): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $settlementId, $on, $actor, $reason) {
        $q = $pdo->prepare('SELECT * FROM ledger.settlements WHERE book_id = :b AND id = :s FOR UPDATE');
        $q->execute(['b' => $bookId, 's' => $settlementId]);
        $s = $q->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            throw new LedgerError('No such payment in these books.');
        }
        if ($s['reversed_at'] !== null) {
            return;
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new LedgerError('Say why the payment is being reversed.');
        }
        foreach (['accrual_entry_id' => 'accrual', 'cash_entry_id' => 'cash', 'assign_entry_id' => 'assign'] as $col => $tag) {
            if ($s[$col] !== null) {
                ledger_reverse($pdo, $bookId, (int) $s[$col], $s['source_key'] . '#reverse-' . $tag, $on, $actor, 'Payment reversed: ' . $reason);
            }
        }
        $pdo->prepare('UPDATE ledger.settlements SET reversed_at = now(), reverse_reason = :r WHERE id = :s')
            ->execute(['r' => mb_substr($reason, 0, 300), 's' => $settlementId]);
    });
}

/**
 * Write off what will not be collected. The owner's decision, recorded with
 * who asked, who approved and why. Accrual: bad debt against receivables.
 * Cash view: nothing, because the revenue never counted there.
 */
function ledger_write_off(PDO $pdo, int $bookId, int $docId, array $w): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $docId, $w) {
        $q = $pdo->prepare('SELECT id FROM ledger.write_offs WHERE book_id = :b AND source_key = :k');
        $q->execute(['b' => $bookId, 'k' => $w['source_key']]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
        $doc = ledger_document_lock($pdo, $bookId, $docId);
        if ($doc['kind'] !== 'receivable' || $doc['status'] !== 'open') {
            throw new LedgerError('Only an open receivable is written off.');
        }
        $amt = (int) ($w['amount'] ?? 0);
        $open = ledger_document_open($pdo, $docId);
        if ($amt <= 0 || $amt > $open) {
            throw new LedgerError(sprintf('A write-off is between 1 and the %d cents still owed.', $open));
        }
        if (trim((string) ($w['approved_by'] ?? '')) === '') {
            throw new LedgerError('A write-off needs the approver on record.');
        }
        $entry = ledger_post($pdo, $bookId, [
            'entry_on' => $w['written_on'], 'basis' => 'accrual', 'source_key' => $w['source_key'] . '#entry',
            'event_type' => 'receivable.written_off', 'rule' => 'core.write_off', 'rule_version' => 1,
            'memo' => 'Write-off on ' . $doc['ref'] . ': ' . ($w['reason'] ?? ''), 'actor' => (string) $w['approved_by'],
            'lines' => [
                ['account' => $w['expense_account'] ?? 'exp.bad_debt', 'debit' => $amt],
                ['account' => (int) $doc['control_account_id'], 'credit' => $amt, 'party' => $doc['party']],
            ],
        ]);
        $q = $pdo->prepare('INSERT INTO ledger.write_offs (book_id, document_id, written_on, amount_cents, reason, requested_by, approved_by, source_key, entry_id)
                            VALUES (:b, :d, :on, :a, :r, :rq, :ap, :sk, :e) RETURNING id');
        $q->execute(['b' => $bookId, 'd' => $docId, 'on' => $w['written_on'], 'a' => $amt, 'r' => mb_substr(trim((string) ($w['reason'] ?? '')), 0, 300),
                     'rq' => mb_substr((string) ($w['requested_by'] ?? ''), 0, 120), 'ap' => mb_substr((string) $w['approved_by'], 0, 120),
                     'sk' => $w['source_key'], 'e' => $entry]);
        return (int) $q->fetchColumn();
    });
}

/* ── Reading the books ──────────────────────────────────────────────── */

/**
 * Trial balance on a basis, through a date: one row per account with any
 * posting, plus totals. Balances are signed debit-positive.
 *
 * @return array{rows: array, debits: int, credits: int, balanced: bool}
 */
function ledger_trial_balance(PDO $pdo, int $bookId, string $basis = 'accrual', ?string $through = null): array
{
    if (!in_array($basis, ['accrual', 'cash'], true)) {
        throw new LedgerError('A basis is accrual or cash.');
    }
    $q = $pdo->prepare("SELECT a.id, a.code, a.name, a.type, a.contra, a.active, a.system_key,
                               SUM(l.debit_cents) AS debits, SUM(l.credit_cents) AS credits
                          FROM ledger.lines l
                          JOIN ledger.entries e ON e.id = l.entry_id
                          JOIN ledger.accounts a ON a.id = l.account_id
                         WHERE l.book_id = :b AND e.basis IN ('both', :basis) AND (CAST(:t AS date) IS NULL OR e.entry_on <= CAST(:t2 AS date))
                         GROUP BY a.id ORDER BY a.code");
    $q->execute(['b' => $bookId, 'basis' => $basis, 't' => $through, 't2' => $through]);
    $rows = [];
    $dr = $cr = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bal = (int) $r['debits'] - (int) $r['credits'];
        if ($bal === 0 && (int) $r['debits'] === 0) {
            continue;
        }
        $rows[] = [
            'account_id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'],
            'system_key' => $r['system_key'], 'active' => (bool) $r['active'],
            'debit' => $bal > 0 ? $bal : 0, 'credit' => $bal < 0 ? -$bal : 0, 'balance' => $bal,
        ];
        $dr += $bal > 0 ? $bal : 0;
        $cr += $bal < 0 ? -$bal : 0;
    }
    return ['rows' => $rows, 'debits' => $dr, 'credits' => $cr, 'balanced' => $dr === $cr, 'basis' => $basis, 'through' => $through];
}

/** One account's balance (debit-positive) on a basis through a date, optionally for one party. */
function ledger_balance(PDO $pdo, int $bookId, int|string $account, string $basis = 'accrual', ?string $through = null, ?string $party = null): int
{
    $acct = ledger_account_ref($pdo, $bookId, $account);
    $q = $pdo->prepare("SELECT coalesce(SUM(l.debit_cents - l.credit_cents), 0)
                          FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                         WHERE l.account_id = :a AND e.basis IN ('both', :basis)
                           AND (CAST(:t AS date) IS NULL OR e.entry_on <= CAST(:t2 AS date))
                           AND (CAST(:p AS text) IS NULL OR l.party = :p2)");
    $q->execute(['a' => $acct, 'basis' => $basis, 't' => $through, 't2' => $through, 'p' => $party, 'p2' => $party]);
    return (int) $q->fetchColumn();
}

/**
 * Control accounts against their sub-ledgers: for each control account,
 * the account balance equals the sum of its parties' balances (always true
 * by construction, since every control line carries a party) and no party
 * has a balance on the wrong side. Returns a list of problems.
 */
function ledger_control_problems(PDO $pdo, int $bookId, string $basis = 'accrual'): array
{
    $q = $pdo->prepare("SELECT a.code, a.name, a.control, l.party, SUM(l.debit_cents - l.credit_cents) AS bal
                          FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                          JOIN ledger.accounts a ON a.id = l.account_id
                         WHERE l.book_id = :b AND a.control <> 'none' AND e.basis IN ('both', :basis)
                         GROUP BY a.code, a.name, a.control, l.party
                        HAVING (a.control = 'trust_liability' AND SUM(l.debit_cents - l.credit_cents) > 0)");
    $q->execute(['b' => $bookId, 'basis' => $basis]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = sprintf('%s %s: %s is overdrawn by %d cents', $r['code'], $r['name'], $r['party'], (int) $r['bal']);
    }
    return $out;
}

/**
 * A SHA-256 over the entries and lines of a book through a date, in id
 * order: two runs over the same books give the same fingerprint, and any
 * change to what was posted changes it.
 */
function ledger_fingerprint(PDO $pdo, int $bookId, ?string $through = null): string
{
    $q = $pdo->prepare("SELECT e.id, e.entry_on, e.basis, e.source_key, l.account_id, l.debit_cents, l.credit_cents, l.party
                          FROM ledger.entries e JOIN ledger.lines l ON l.entry_id = e.id
                         WHERE e.book_id = :b AND (CAST(:t AS date) IS NULL OR e.entry_on <= CAST(:t2 AS date))
                         ORDER BY e.id, l.id");
    $q->execute(['b' => $bookId, 't' => $through, 't2' => $through]);
    $h = hash_init('sha256');
    while ($r = $q->fetch(PDO::FETCH_NUM)) {
        hash_update($h, implode("\x1f", array_map('strval', $r)) . "\x1e");
    }
    return hash_final($h);
}

require_once __DIR__ . '/statements.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/forecast.php';
require_once __DIR__ . '/bank.php';
