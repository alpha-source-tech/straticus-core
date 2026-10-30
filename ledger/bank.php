<?php
declare(strict_types=1);

/**
 * Bank reconciliation for any cash account. The host parses the bank's
 * file; the core keeps the lines, matches them to the journal and works out
 * the reconciliation:
 *
 *   bank closing balance
 *   + money the books show in that the bank does not yet (deposits in transit)
 *   - money the books show out that the bank does not yet (outstanding checks)
 *   = the books' balance, once every bank-only item (fees, interest) is posted.
 */

require_once __DIR__ . '/ledger.php';

/**
 * Import a statement's lines into a cash account. $lines: each [posted_on,
 * amount_cents (in +, out -), description, ref, bank_id]. Returns
 * [added, already there]. Lines must fall inside the statement period.
 */
function ledger_bank_import(PDO $pdo, int $bookId, int|string $account, string $from, string $to, ?int $closing, array $lines): array
{
    $acct = ledger_account_ref($pdo, $bookId, $account);
    if (!ledger_is_date($from) || !ledger_is_date($to) || $to < $from) {
        throw new LedgerError('A statement needs its period.');
    }
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $acct, $from, $to, $closing, $lines) {
        $added = $dupes = 0;
        $ins = $pdo->prepare('INSERT INTO ledger.bank_lines (book_id, account_id, posted_on, amount_cents, description, ref, bank_id, statement_end, statement_closing_cents)
                              VALUES (:b, :a, :on, :amt, :d, :r, :id, :end, :cl) ON CONFLICT (account_id, bank_id) DO NOTHING');
        foreach ($lines as [$on, $amt, $desc, $ref, $bankId]) {
            if ($on < $from || $on > $to) {
                throw new LedgerError("A line dated $on is outside the statement period $from to $to.");
            }
            if ((int) $amt === 0) {
                continue;
            }
            $ins->execute(['b' => $bookId, 'a' => $acct, 'on' => $on, 'amt' => (int) $amt, 'd' => mb_substr((string) $desc, 0, 300),
                           'r' => mb_substr((string) $ref, 0, 60), 'id' => mb_substr((string) $bankId, 0, 120), 'end' => $to, 'cl' => $closing]);
            $ins->rowCount() ? $added++ : $dupes++;
        }
        return [$added, $dupes];
    });
}

/** Journal lines on a cash account that no bank line matches yet (through a date). */
function ledger_bank_unmatched_book(PDO $pdo, int $bookId, int $accountId, ?string $through = null): array
{
    $q = $pdo->prepare("SELECT l.id, e.entry_on, l.debit_cents - l.credit_cents AS amount, coalesce(nullif(l.memo, ''), e.memo) AS memo, e.id AS entry_id
                          FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                         WHERE l.book_id = :b AND l.account_id = :a AND e.basis IN ('both','accrual')
                           AND (CAST(:t AS date) IS NULL OR e.entry_on <= CAST(:t2 AS date))
                           AND NOT EXISTS (SELECT 1 FROM ledger.bank_lines bl WHERE bl.matched_line_id = l.id)
                         ORDER BY e.entry_on, l.id");
    $q->execute(['b' => $bookId, 'a' => $accountId, 't' => $through, 't2' => $through]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function ledger_bank_unmatched_bank(PDO $pdo, int $bookId, int $accountId, ?string $through = null): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.bank_lines WHERE book_id = :b AND account_id = :a AND matched_line_id IS NULL
                          AND (CAST(:t AS date) IS NULL OR posted_on <= CAST(:t2 AS date)) ORDER BY posted_on, id');
    $q->execute(['b' => $bookId, 'a' => $accountId, 't' => $through, 't2' => $through]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Match cautiously: same amount, dates within $days, and only when each side
 * is the other's sole candidate. Returns the number matched.
 */
function ledger_bank_auto_match(PDO $pdo, int $bookId, int|string $account, string $by, int $days = 10): int
{
    $acct = ledger_account_ref($pdo, $bookId, $account);
    $book = ledger_bank_unmatched_book($pdo, $bookId, $acct);
    $bank = ledger_bank_unmatched_bank($pdo, $bookId, $acct);
    $near = fn($a, $b) => abs((strtotime((string) $a) - strtotime((string) $b)) / 86400) <= $days;
    $n = 0;
    foreach ($bank as $bl) {
        $cands = array_values(array_filter($book, fn($l) => (int) $l['amount'] === (int) $bl['amount_cents'] && $near($l['entry_on'], $bl['posted_on'])));
        if (count($cands) !== 1) {
            continue;
        }
        $c = $cands[0];
        $back = array_filter($bank, fn($x) => (int) $x['amount_cents'] === (int) $c['amount'] && $near($c['entry_on'], $x['posted_on']));
        if (count($back) !== 1) {
            continue;
        }
        ledger_bank_match($pdo, $bookId, (int) $bl['id'], (int) $c['id'], $by);
        $book = array_values(array_filter($book, fn($l) => (int) $l['id'] !== (int) $c['id']));
        $n++;
    }
    return $n;
}

function ledger_bank_match(PDO $pdo, int $bookId, int $bankLineId, int $journalLineId, string $by): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $bankLineId, $journalLineId, $by) {
        $q = $pdo->prepare('UPDATE ledger.bank_lines SET matched_line_id = :l, matched_by = :by, matched_at = now() WHERE book_id = :b AND id = :id AND matched_line_id IS NULL');
        $q->execute(['l' => $journalLineId, 'by' => mb_substr($by, 0, 120), 'b' => $bookId, 'id' => $bankLineId]);
        if ($q->rowCount() !== 1) {
            throw new LedgerError('That bank line is already matched.');
        }
    });
}

function ledger_bank_unmatch(PDO $pdo, int $bookId, int $bankLineId): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $bankLineId) {
        $pdo->prepare('UPDATE ledger.bank_lines SET matched_line_id = NULL, matched_by = \'\', matched_at = NULL WHERE book_id = :b AND id = :id')
            ->execute(['b' => $bookId, 'id' => $bankLineId]);
    });
}

/**
 * A line only the bank has (a fee, interest, a transfer nobody recorded):
 * post it from the bank line, against the account the person chooses, and
 * match it. Idempotent per bank line.
 */
function ledger_bank_post_line(PDO $pdo, int $bookId, int $bankLineId, int|string $against, string $by, string $memo = ''): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $bankLineId, $against, $by, $memo) {
        $q = $pdo->prepare('SELECT * FROM ledger.bank_lines WHERE book_id = :b AND id = :id FOR UPDATE');
        $q->execute(['b' => $bookId, 'id' => $bankLineId]);
        $bl = $q->fetch(PDO::FETCH_ASSOC);
        if (!$bl) {
            throw new LedgerError('No such bank line.');
        }
        if ($bl['matched_line_id'] !== null) {
            throw new LedgerError('That bank line is already in the books.');
        }
        $amt = (int) $bl['amount_cents'];
        $entry = ledger_post($pdo, $bookId, [
            'entry_on' => $bl['posted_on'], 'basis' => 'both', 'source_key' => 'bank:' . $bl['id'], 'event_type' => 'bank.line',
            'rule' => 'core.bank', 'rule_version' => 1, 'memo' => $memo !== '' ? $memo : ('Bank: ' . $bl['description']), 'actor' => $by,
            'lines' => [['account' => (int) $bl['account_id'], 'debit' => $amt, 'memo' => $bl['description']],
                        ['account' => $against, 'credit' => $amt]],
        ]);
        $l = $pdo->prepare('SELECT id FROM ledger.lines WHERE entry_id = :e AND account_id = :a ORDER BY id LIMIT 1');
        $l->execute(['e' => $entry, 'a' => $bl['account_id']]);
        ledger_bank_match($pdo, $bookId, $bankLineId, (int) $l->fetchColumn(), $by);
        return $entry;
    });
}

/**
 * The reconciliation at a statement's end: the bank's closing balance,
 * items in transit either way, the adjusted bank balance, the books'
 * balance and the bank-only items still to post.
 */
function ledger_bank_reconciliation(PDO $pdo, int $bookId, int|string $account, string $statementEnd): array
{
    $acct = ledger_account_ref($pdo, $bookId, $account);
    $q = $pdo->prepare('SELECT statement_closing_cents FROM ledger.bank_lines WHERE account_id = :a AND statement_end = :e AND statement_closing_cents IS NOT NULL LIMIT 1');
    $q->execute(['a' => $acct, 'e' => $statementEnd]);
    $closing = $q->fetchColumn();
    // In the books by the end, not yet seen by the bank (or seen only after the end).
    $transit = $outstanding = 0;
    $open = [];
    $q = $pdo->prepare("SELECT l.id, e.entry_on, l.debit_cents - l.credit_cents AS amount, coalesce(nullif(l.memo, ''), e.memo) AS memo
                          FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                         WHERE l.account_id = :a AND e.basis IN ('both','accrual') AND e.entry_on <= :t
                           AND NOT EXISTS (SELECT 1 FROM ledger.bank_lines bl WHERE bl.matched_line_id = l.id AND bl.posted_on <= :t2)
                         ORDER BY e.entry_on, l.id");
    $q->execute(['a' => $acct, 't' => $statementEnd, 't2' => $statementEnd]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $amt = (int) $l['amount'];
        $amt > 0 ? $transit += $amt : $outstanding += -$amt;
        $open[] = $l;
    }
    $bankOnly = array_filter(ledger_bank_unmatched_bank($pdo, $bookId, $acct, $statementEnd), fn() => true);
    $bankOnlyNet = array_sum(array_map(fn($b) => (int) $b['amount_cents'], $bankOnly));
    $books = ledger_balance($pdo, $bookId, $acct, 'accrual', $statementEnd);
    $adjusted = $closing === false || $closing === null ? null : (int) $closing + $transit - $outstanding - $bankOnlyNet;
    return [
        'account_id' => $acct, 'statement_end' => $statementEnd, 'bank_closing' => $closing === false ? null : ($closing === null ? null : (int) $closing),
        'in_transit' => $transit, 'outstanding' => $outstanding, 'bank_only' => array_values($bankOnly), 'bank_only_net' => $bankOnlyNet,
        'adjusted_bank' => $adjusted, 'books' => $books, 'open_book_items' => $open,
        'reconciled' => $adjusted !== null && $adjusted === $books && !$bankOnly,
    ];
}
