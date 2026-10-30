<?php
declare(strict_types=1);

/**
 * Tests for the STRATICUS accounting core. They use only the core's own
 * API, so they run under any host. The host supplies two hooks:
 *
 *   ledger_test_book(string $tag): array   opens fresh books for a fresh
 *       tenant, narrows the connection to that tenant, and returns
 *       ['pdo' => PDO, 'book' => int]
 *   ledger_test_other_tenant(callable $fn): mixed   runs $fn while the
 *       connection is narrowed to a different tenant, then restores scope
 *
 * and check() / check_same() / check_refused() assertions.
 */

function lt_invoice(PDO $pdo, int $book, string $key, string $on, array $lines, string $party = 'client:1'): int
{
    return ledger_document_issue($pdo, $book, [
        'kind' => 'receivable', 'party' => $party, 'ref' => $key, 'issued_on' => $on, 'source_key' => $key,
        'lines' => array_map(fn($l) => ['account' => $l[0], 'amount' => $l[1], 'memo' => $l[2] ?? ''], $lines),
    ]);
}

function lt_line_ids(PDO $pdo, int $doc): array
{
    return array_column(ledger_document_lines($pdo, $doc), 'id');
}

function lt_balance(PDO $pdo, int $book, string $key, string $basis, ?string $through = null): int
{
    return ledger_balance($pdo, $book, $key, $basis, $through);
}

function test_ledger_entries_must_balance_and_stay_as_posted(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('balance');
    // The engine refuses an unbalanced or one-line entry before sending it.
    foreach ([
        [['account' => 'cash.operating', 'debit' => 100], ['account' => 'revenue.services', 'credit' => 90]],
        [['account' => 'cash.operating', 'debit' => 100]],
    ] as $lines) {
        try {
            ledger_post($pdo, $b, ['entry_on' => '2026-01-05', 'lines' => $lines]);
            check(false, 'an unbalanced entry was posted');
        } catch (LedgerError) {
        }
    }
    // So does the database, at commit, whoever writes the rows.
    $cash = ledger_account_id($pdo, $b, 'cash.operating');
    $rev  = ledger_account_id($pdo, $b, 'revenue.services');
    // Control: the same rows, balanced, are accepted (so the refusals below are the guards, not a missing grant).
    $pdo->beginTransaction();
    $ok = $pdo->query("INSERT INTO ledger.entries (book_id, entry_on) VALUES ($b, '2026-01-05') RETURNING id")->fetchColumn();
    $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, debit_cents) VALUES ($b, $ok, $cash, 100)");
    $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, credit_cents) VALUES ($b, $ok, $rev, 100)");
    $pdo->commit();
    check_same(100, lt_balance($pdo, $b, 'cash.operating', 'accrual'), 'a balanced raw entry was not kept');
    check_refused(function () use ($pdo, $b, $cash, $rev) {
        $pdo->beginTransaction();
        try {
            $e = $pdo->query("INSERT INTO ledger.entries (book_id, entry_on) VALUES ($b, '2026-01-05') RETURNING id")->fetchColumn();
            $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, debit_cents) VALUES ($b, $e, $cash, 100)");
            $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, credit_cents) VALUES ($b, $e, $rev, 99)");
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $x;
        }
    }, 'the database kept an unbalanced entry');
    check_refused(function () use ($pdo, $b) {
        $pdo->beginTransaction();
        try {
            $pdo->query("INSERT INTO ledger.entries (book_id, entry_on) VALUES ($b, '2026-01-05')");
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $x;
        }
    }, 'the database kept an entry with no lines');

    // A good entry posts once per source key, and is then fixed.
    $e = ledger_post($pdo, $b, ['entry_on' => '2026-01-05', 'source_key' => 'sale:1', 'lines' => [
        ['account' => 'cash.operating', 'debit' => 10000], ['account' => 'revenue.services', 'credit' => 10000]]]);
    check_same($e, ledger_post($pdo, $b, ['entry_on' => '2026-01-09', 'source_key' => 'sale:1', 'lines' => [
        ['account' => 'cash.operating', 'debit' => 1], ['account' => 'revenue.services', 'credit' => 1]]]), 'a source key posted twice');
    check_refused(fn() => $pdo->exec("UPDATE ledger.entries SET entry_on = '2026-02-01' WHERE id = $e"), 'an entry was changed');
    check_refused(fn() => $pdo->exec("UPDATE ledger.lines SET debit_cents = 1 WHERE entry_id = $e"), 'a line was changed');
    check_refused(fn() => $pdo->exec("DELETE FROM ledger.lines WHERE entry_id = $e"), 'a line was deleted');
    check_refused(fn() => $pdo->exec("DELETE FROM ledger.entries WHERE id = $e"), 'an entry was deleted');
    // Lines cannot be added to an entry after the fact.
    check_refused(fn() => $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, debit_cents) VALUES ($b, $e, $cash, 5)"),
        'a line was added to an old entry');

    // A correction is a reversal: same lines, sides swapped, once only.
    $r = ledger_reverse($pdo, $b, $e, 'sale:1#reverse', '2026-01-10', 'tester', 'Entered twice');
    check_same($r, ledger_reverse($pdo, $b, $e, 'sale:1#reverse-again', '2026-01-11', 'tester', 'again'), 'an entry was reversed twice');
    check_same(-100, lt_balance($pdo, $b, 'revenue.services', 'accrual'), 'the reversal did not cancel the sale (only the control entry remains)');
    try {
        ledger_reverse($pdo, $b, $r, 'sale:1#rr', '2026-01-12', 'tester', 'undo');
        check(false, 'a reversal was reversed');
    } catch (LedgerError) {
    }
    $tb = ledger_trial_balance($pdo, $b, 'accrual');
    check($tb['balanced'], 'the trial balance does not balance');
    check_same(100, lt_balance($pdo, $b, 'cash.operating', 'accrual'), 'refused rows left something behind');
}

function test_ledger_accounts_parties_and_locked_periods(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('rules');
    // A control account needs a party on every line.
    try {
        ledger_post($pdo, $b, ['entry_on' => '2026-02-01', 'lines' => [
            ['account' => 'ar', 'debit' => 500], ['account' => 'revenue.services', 'credit' => 500]]]);
        check(false, 'a receivable line without a party was posted');
    } catch (LedgerError) {
    }
    // A closed account takes nothing new.
    $soft = ledger_account_id($pdo, $b, 'exp.software');
    $pdo->exec("UPDATE ledger.accounts SET active = false WHERE id = $soft");
    try {
        ledger_post($pdo, $b, ['entry_on' => '2026-02-01', 'lines' => [
            ['account' => 'exp.software', 'debit' => 500], ['account' => 'cash.operating', 'credit' => 500]]]);
        check(false, 'a closed account took a posting');
    } catch (LedgerError) {
    }
    $pdo->exec("UPDATE ledger.accounts SET active = true WHERE id = $soft");
    ledger_post($pdo, $b, ['entry_on' => '2026-02-01', 'lines' => [
        ['account' => 'exp.software', 'debit' => 500], ['account' => 'cash.operating', 'credit' => 500]]]);
    // An account with postings keeps what it is.
    check_refused(fn() => $pdo->exec("UPDATE ledger.accounts SET type = 'asset' WHERE id = $soft"), 'an account with postings changed type');

    // Locked through January: nothing is dated in it, the engine posts on the first open day.
    $pdo->exec("UPDATE ledger.books SET locked_through = '2026-01-31' WHERE id = $b");
    $cash = ledger_account_id($pdo, $b, 'cash.operating');
    $rev  = ledger_account_id($pdo, $b, 'revenue.services');
    check_refused(function () use ($pdo, $b, $cash, $rev) {
        $pdo->beginTransaction();
        try {
            $e = $pdo->query("INSERT INTO ledger.entries (book_id, entry_on) VALUES ($b, '2026-01-20') RETURNING id")->fetchColumn();
            $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, debit_cents) VALUES ($b, $e, $cash, 100)");
            $pdo->exec("INSERT INTO ledger.lines (book_id, entry_id, account_id, credit_cents) VALUES ($b, $e, $rev, 100)");
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $x;
        }
    }, 'an entry was dated inside a locked period');
    $e = ledger_post($pdo, $b, ['entry_on' => '2026-01-20', 'memo' => 'late', 'lines' => [
        ['account' => 'cash.operating', 'debit' => 100], ['account' => 'revenue.services', 'credit' => 100]]]);
    $row = $pdo->query("SELECT entry_on, original_on FROM ledger.entries WHERE id = $e")->fetch(PDO::FETCH_ASSOC);
    check_same(['2026-02-01', '2026-01-20'], [(string) $row['entry_on'], (string) $row['original_on']], 'a late entry was not moved to the first open day');
    check_refused(fn() => $pdo->exec("UPDATE ledger.books SET locked_through = '2025-12-31' WHERE id = $b"), 'the lock moved backwards');
}

function test_ledger_part_payments_are_assigned_and_both_bases_balance(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('bases');
    // A $1,000 invoice in October: $700 of fees, $300 of costs.
    $doc = lt_invoice($pdo, $b, 'inv:1', '2026-10-10', [['revenue.services', 70000, 'Fees'], ['revenue.other', 30000, 'Costs']]);
    [$fees, $costs] = lt_line_ids($pdo, $doc);
    check_same(100000, lt_balance($pdo, $b, 'ar', 'accrual'), 'receivable on accrual');
    check_same(0, lt_balance($pdo, $b, 'ar', 'cash'), 'a receivable showed on the cash view');

    // $400 in October with no assignment: it waits in unapplied receipts on the cash view.
    $s1 = ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-10-20', 'amount' => 40000, 'source_key' => 'pay:1']);
    check_same(-40000, lt_balance($pdo, $b, 'unapplied_receipts', 'cash'), 'the unassigned payment is not waiting');
    check_same(1, count(ledger_unassigned($pdo, $b)), 'the waiting payment is not listed');
    // An assignment must add up and stay within what each line owes.
    foreach ([[$fees => 30000], [$fees => 10000, $costs => 30001], [$costs => 40000]] as $bad) {
        try {
            ledger_settlement_assign($pdo, $b, $s1, $bad, 'tester');
            check(false, 'a bad assignment was accepted: ' . json_encode($bad));
        } catch (LedgerError) {
        }
    }
    ledger_settlement_assign($pdo, $b, $s1, [$fees => 40000], 'tester');
    check_same(0, lt_balance($pdo, $b, 'unapplied_receipts', 'cash'), 'the assignment left money unapplied');
    try {
        ledger_settlement_assign($pdo, $b, $s1, [$fees => 40000], 'tester');
        check(false, 'a payment was assigned twice');
    } catch (LedgerError) {
    }

    // $600 in November clears it: assigned to what each line still owes, on its own.
    $s2 = ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-11-05', 'amount' => 60000, 'source_key' => 'pay:2']);
    check_same(0, count(ledger_unassigned($pdo, $b)), 'the clearing payment was not assigned');
    check_same(0, ledger_document_open($pdo, $doc), 'the invoice still owes');
    try {
        ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-11-06', 'amount' => 1, 'source_key' => 'pay:3']);
        check(false, 'a payment beyond what was owed was taken');
    } catch (LedgerError) {
    }

    // Accrual: all $1,000 counts in October. Cash: $400 in October, $600 in November.
    check_same(-100000, lt_balance($pdo, $b, 'revenue.services', 'accrual', '2026-10-31') + lt_balance($pdo, $b, 'revenue.other', 'accrual', '2026-10-31'), 'accrual revenue in October');
    check_same(-40000, lt_balance($pdo, $b, 'revenue.services', 'cash', '2026-10-31'), 'cash revenue in October');
    check_same(0, lt_balance($pdo, $b, 'revenue.other', 'cash', '2026-10-31'), 'cash costs in October');
    check_same(-70000, lt_balance($pdo, $b, 'revenue.services', 'cash'), 'cash fees by November');
    check_same(-30000, lt_balance($pdo, $b, 'revenue.other', 'cash'), 'cash costs by November');
    foreach (['accrual', 'cash'] as $basis) {
        foreach (['2026-10-31', null] as $through) {
            check(ledger_trial_balance($pdo, $b, $basis, $through)['balanced'], "the $basis trial balance does not balance through " . ($through ?? 'today'));
        }
    }

    // A payment recorded in error is reversed: the invoice owes it again on both bases.
    ledger_settlement_reverse($pdo, $b, $s2, '2026-11-07', 'tester', 'bounced');
    check_same(60000, ledger_document_open($pdo, $doc), 'the reversal did not reopen the invoice');
    check_same(60000, lt_balance($pdo, $b, 'ar', 'accrual'), 'the receivable did not come back');
    check_same(-40000, lt_balance($pdo, $b, 'revenue.services', 'cash'), 'cash revenue after the reversal');
    check_refused(fn() => $pdo->exec("UPDATE ledger.settlements SET amount_cents = 1 WHERE id = $s1"), 'a payment amount changed');
    check_refused(fn() => $pdo->exec("DELETE FROM ledger.allocations WHERE settlement_id = $s1"), 'an assignment was deleted');
    check(ledger_trial_balance($pdo, $b, 'cash')['balanced'] && ledger_trial_balance($pdo, $b, 'accrual')['balanced'], 'the books stopped balancing');
}

function test_ledger_allocations_add_up_in_the_database(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('alloc-db');
    $doc = lt_invoice($pdo, $b, 'inv:9', '2026-03-01', [['revenue.services', 10000]]);
    [$line] = lt_line_ids($pdo, $doc);
    $s = ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-03-02', 'amount' => 4000, 'source_key' => 'pay:9']);
    check_refused(function () use ($pdo, $b, $s, $line) {
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO ledger.allocations (book_id, settlement_id, document_line_id, amount_cents) VALUES ($b, $s, $line, 3000)");
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $x;
        }
    }, 'the database kept an assignment that did not add up');
}

function test_ledger_write_offs_count_on_accrual_only(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('writeoff');
    $doc = lt_invoice($pdo, $b, 'inv:wo', '2026-04-01', [['revenue.services', 50000, 'Fees']]);
    ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-04-10', 'amount' => 20000, 'source_key' => 'pay:wo',
        'allocations' => [lt_line_ids($pdo, $doc)[0] => 20000]]);
    try {
        ledger_write_off($pdo, $b, $doc, ['written_on' => '2026-05-01', 'amount' => 30000, 'reason' => 'client gone', 'source_key' => 'wo:1', 'approved_by' => '']);
        check(false, 'a write-off went in without an approver');
    } catch (LedgerError) {
    }
    try {
        ledger_write_off($pdo, $b, $doc, ['written_on' => '2026-05-01', 'amount' => 30001, 'reason' => 'too much', 'source_key' => 'wo:2', 'approved_by' => 'owner']);
        check(false, 'more than was owed was written off');
    } catch (LedgerError) {
    }
    ledger_write_off($pdo, $b, $doc, ['written_on' => '2026-05-01', 'amount' => 30000, 'reason' => 'client gone', 'source_key' => 'wo:1',
        'requested_by' => 'attorney', 'approved_by' => 'owner']);
    check_same(30000, lt_balance($pdo, $b, 'exp.bad_debt', 'accrual'), 'bad debt on accrual');
    check_same(0, lt_balance($pdo, $b, 'exp.bad_debt', 'cash'), 'a write-off showed on the cash view');
    check_same(0, lt_balance($pdo, $b, 'ar', 'accrual'), 'the receivable was not cleared');
    check_same(-20000, lt_balance($pdo, $b, 'revenue.services', 'cash'), 'cash revenue is only what was paid');
    check_same(0, ledger_document_open($pdo, $doc), 'the invoice still owes after the write-off');
    try {
        ledger_document_void($pdo, $b, $doc, '2026-05-02', 'owner');
        check(false, 'a document with payments and a write-off was voided');
    } catch (LedgerError) {
    }
    check(ledger_trial_balance($pdo, $b, 'cash')['balanced'] && ledger_trial_balance($pdo, $b, 'accrual')['balanced'], 'the books stopped balancing');
}

function test_ledger_bills_paid_count_on_cash_when_paid(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('bills');
    $bill = ledger_document_issue($pdo, $b, ['kind' => 'payable', 'party' => 'vendor:print', 'ref' => 'Bill 77', 'issued_on' => '2026-06-28',
        'source_key' => 'bill:77', 'lines' => [['account' => 'exp.office', 'amount' => 12000, 'memo' => 'Toner']]]);
    check_same(-12000, lt_balance($pdo, $b, 'ap', 'accrual'), 'payable on accrual');
    check_same(12000, lt_balance($pdo, $b, 'exp.office', 'accrual', '2026-06-30'), 'accrual expense in June');
    check_same(0, lt_balance($pdo, $b, 'exp.office', 'cash', '2026-06-30'), 'an unpaid bill counted on the cash view');
    ledger_settle($pdo, $b, $bill, ['settled_on' => '2026-07-03', 'amount' => 12000, 'source_key' => 'billpay:77']);
    check_same(12000, lt_balance($pdo, $b, 'exp.office', 'cash'), 'cash expense when paid');
    check_same(-12000, lt_balance($pdo, $b, 'cash.operating', 'cash'), 'cash went out on the cash view');
    check_same(-12000, lt_balance($pdo, $b, 'cash.operating', 'accrual'), 'cash went out on accrual');
    check_same(0, lt_balance($pdo, $b, 'ap', 'accrual'), 'the payable was not cleared');
}

function test_ledger_entity_types_and_fingerprint(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('entity');
    try {
        ledger_book_set_entity($pdo, $b, 'sole_proprietor', 's_corp');
        check(false, 'a sole proprietorship was taxed as an S corporation');
    } catch (LedgerError) {
    }
    ledger_book_set_entity($pdo, $b, 'pllc', 'partnership');
    ledger_account_id($pdo, $b, 'exp.guaranteed_payments');
    $name = $pdo->query("SELECT name FROM ledger.accounts WHERE book_id = $b AND system_key = 'equity.capital'")->fetchColumn();
    check_same("Partners' capital", $name, 'the capital account was not renamed for a partnership');
    ledger_book_set_entity($pdo, $b, 'pllc', 's_corp');
    $draws = $pdo->query("SELECT active FROM ledger.accounts WHERE book_id = $b AND system_key = 'equity.draws'")->fetchColumn();
    check(!$draws, 'draws stayed open for an S corporation');
    ledger_account_id($pdo, $b, 'equity.distributions');

    $f1 = ledger_fingerprint($pdo, $b);
    check_same($f1, ledger_fingerprint($pdo, $b), 'the fingerprint is not stable');
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [
        ['account' => 'cash.operating', 'debit' => 1], ['account' => 'equity.opening', 'credit' => 1]]]);
    check($f1 !== ledger_fingerprint($pdo, $b), 'the fingerprint did not change with a new entry');
}

function test_ledger_tenants_see_only_their_own_books(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('tenant');
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [
        ['account' => 'cash.operating', 'debit' => 100], ['account' => 'equity.opening', 'credit' => 100]]]);
    $seen = ledger_test_other_tenant(function () use ($pdo, $b) {
        $n = 0;
        foreach (['books' => 'id', 'accounts' => 'book_id', 'entries' => 'book_id', 'lines' => 'book_id'] as $t => $col) {
            $n += (int) $pdo->query("SELECT COUNT(*) FROM ledger.$t WHERE $col = $b")->fetchColumn();
        }
        // Nor can another tenant post into these books.
        try {
            ledger_post($pdo, $b, ['entry_on' => '2026-01-03', 'lines' => [
                ['account' => 'cash.operating', 'debit' => 1], ['account' => 'equity.opening', 'credit' => 1]]]);
            $n++;
        } catch (Throwable) {
        }
        return $n;
    });
    check_same(0, $seen, 'another tenant saw or wrote these books');
}

/**
 * A small business over January and February, with every figure worked by
 * hand: $10,000 put in, $3,000 of equipment, a $5,000 invoice (January),
 * $2,000 paid on it (February), an $800 bill (January) paid in February,
 * $100 of depreciation and a $500 draw (February), and $1,000 of someone
 * else's money held in a restricted account.
 */
function lt_small_business(): array
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('statements');
    ledger_account_ensure($pdo, $b, ['1060', 'Held for others', 'asset', 'Restricted cash: client trust', 'restricted_cash', ['key' => 't.held_cash']]);
    ledger_account_ensure($pdo, $b, ['2550', 'Funds held for others', 'liability', 'Other current liabilities', 'operating', ['key' => 't.held', 'control' => 'trust_liability']]);
    $post = fn(string $on, array $lines, string $memo = '') => ledger_post($pdo, $b, ['entry_on' => $on, 'memo' => $memo, 'lines' => $lines]);
    $post('2026-01-02', [['account' => 'cash.operating', 'debit' => 1000000], ['account' => 'equity.capital', 'credit' => 1000000]], 'Owner puts in $10,000');
    $post('2026-01-05', [['account' => 'fixed.equipment', 'debit' => 300000], ['account' => 'cash.operating', 'credit' => 300000]], 'Equipment');
    $post('2026-01-06', [['account' => 't.held_cash', 'debit' => 100000], ['account' => 't.held', 'credit' => 100000, 'party' => 'client:9']], 'Held');
    $inv = lt_invoice($pdo, $b, 'inv:sb', '2026-01-15', [['revenue.services', 500000, 'Consulting']]);
    $bill = ledger_document_issue($pdo, $b, ['kind' => 'payable', 'party' => 'vendor:1', 'ref' => 'Bill 1', 'issued_on' => '2026-01-20', 'source_key' => 'bill:sb',
        'lines' => [['account' => 'exp.office', 'amount' => 80000, 'memo' => 'Supplies']]]);
    $pay = ledger_settle($pdo, $b, $inv, ['settled_on' => '2026-02-10', 'amount' => 200000, 'source_key' => 'pay:sb']);
    ledger_settlement_assign($pdo, $b, $pay, [lt_line_ids($pdo, $inv)[0] => 200000], 'tester');
    ledger_settle($pdo, $b, $bill, ['settled_on' => '2026-02-12', 'amount' => 80000, 'source_key' => 'billpay:sb']);
    $post('2026-02-28', [['account' => 'exp.depreciation', 'debit' => 10000], ['account' => 'fixed.accum_depr', 'credit' => 10000]], 'Depreciation');
    $post('2026-02-20', [['account' => 'equity.draws', 'debit' => 50000], ['account' => 'cash.operating', 'credit' => 50000]], 'Draw');
    return ['pdo' => $pdo, 'book' => $b];
}

function test_ledger_statements_agree_on_both_bases(): void
{
    ['pdo' => $pdo, 'book' => $b] = lt_small_business();
    // Profit and loss.
    $jan = ledger_profit_and_loss($pdo, $b, 'accrual', '2026-01-01', '2026-01-31');
    check_same([500000, 80000, 420000], [$jan['sections']['revenue']['total'], $jan['sections']['opex']['total'], $jan['net_income']], 'January accrual P&L');
    $feb = ledger_profit_and_loss($pdo, $b, 'accrual', '2026-02-01', '2026-02-28');
    check_same(-10000, $feb['net_income'], 'February accrual net income');
    $janCash = ledger_profit_and_loss($pdo, $b, 'cash', '2026-01-01', '2026-01-31');
    check_same(0, $janCash['net_income'], 'nothing was paid in January');
    $febCash = ledger_profit_and_loss($pdo, $b, 'cash', '2026-02-01', '2026-02-28');
    check_same([200000, 90000, 110000], [$febCash['sections']['revenue']['total'], $febCash['sections']['opex']['total'], $febCash['net_income']], 'February cash P&L');

    // Balance sheet at the end of February, accrual.
    $bs = ledger_balance_sheet($pdo, $b, 'accrual', '2026-02-28');
    check($bs['balanced'], 'the balance sheet does not balance');
    check_same([1460000, 100000, 1360000, 410000], [$bs['total_assets'], $bs['total_liabilities'], $bs['total_equity'], $bs['current_year_earnings']], 'balance sheet totals');
    // Cash basis: no receivable or payable, and the same cash.
    $bsCash = ledger_balance_sheet($pdo, $b, 'cash', '2026-02-28');
    check($bsCash['balanced'], 'the cash-basis balance sheet does not balance');
    check_same(1160000, $bsCash['total_assets'], 'cash-basis assets: no receivable');

    // Cash flow for February, accrual: -$100 + $2,000 receivable - $800 payable + $100 depreciation, then a $500 draw.
    $cf = ledger_cash_flow($pdo, $b, 'accrual', '2026-02-01', '2026-02-28');
    check_same([120000, 0, -50000, 70000], [$cf['operating']['total'], $cf['investing']['total'], $cf['financing']['total'], $cf['net_change']], 'February cash flow');
    check_same(['cash' => 770000, 'restricted' => 100000, 'total' => 870000], $cf['closing'], 'closing cash, restricted shown apart');
    check($cf['ties'], 'the cash flow does not tie');
    $cfJan = ledger_cash_flow($pdo, $b, 'accrual', '2026-01-01', '2026-01-31');
    check_same([-300000, 1000000], [$cfJan['investing']['total'], $cfJan['financing']['total']], 'January investing and financing');

    // Owner's equity for February: $14,200 opening, $500 drawn, -$100 earned, $13,600 closing.
    $eq = ledger_equity_statement($pdo, $b, 'accrual', '2026-02-01', '2026-02-28');
    check_same([1420000, -50000, -10000, 1360000], [$eq['totals']['opening'], $eq['totals']['draws'], $eq['totals']['net_income'], $eq['totals']['closing']], 'equity statement');

    foreach (['accrual', 'cash'] as $basis) {
        foreach ([['2026-01-01', '2026-01-31'], ['2026-02-01', '2026-02-28'], ['2026-01-01', '2026-02-28']] as [$f, $t]) {
            check_same([], ledger_tie_outs($pdo, $b, $basis, $f, $t), "tie-outs, $basis, $f to $t");
        }
    }

    // Comparisons: February against January, with the change and percentage.
    $cmp = ledger_profit_and_loss_compared($pdo, $b, 'accrual', '2026-02-01', '2026-02-28');
    check_same(['2026-01-01', '2026-01-31'], $cmp['periods']['prior_period'], 'prior period of a month');
    check_same(['2025-02-01', '2025-02-28'], $cmp['periods']['prior_year'], 'prior year');
    $rev = $cmp['sections']['revenue']['rows'][0];
    check_same([0, 500000, -500000, -100.0], [$rev['amount'], $rev['prior_period'], $rev['vs_prior_period']['change'], $rev['vs_prior_period']['pct']], 'revenue against January');
    check_same(420000, $cmp['net_income_compared']['prior_period'], 'net income compared');
}

function test_ledger_close_signs_locks_and_verifies(): void
{
    ['pdo' => $pdo, 'book' => $b] = lt_small_business();
    $c = ledger_close_period($pdo, $b, '2026-01-01', '2026-01-31', 'owner:1', 'January reviewed');
    check(ledger_close_verify($pdo, $b, $c), 'a fresh close does not verify');
    check_same('2026-01-31', (string) $pdo->query("SELECT locked_through FROM ledger.books WHERE id = $b")->fetchColumn(), 'January is not locked');
    // A late January item lands in February, and January's signed set still stands.
    $e = ledger_post($pdo, $b, ['entry_on' => '2026-01-29', 'lines' => [['account' => 'exp.software', 'debit' => 1500], ['account' => 'cash.operating', 'credit' => 1500]]]);
    check_same('2026-02-01', (string) $pdo->query("SELECT entry_on FROM ledger.entries WHERE id = $e")->fetchColumn(), 'a late entry was dated into a closed month');
    check(ledger_close_verify($pdo, $b, $c), 'January stopped verifying after a February entry');
    // Periods close in order, once.
    foreach ([['2026-01-01', '2026-01-31'], ['2026-03-01', '2026-03-31']] as [$f, $t]) {
        try {
            ledger_close_period($pdo, $b, $f, $t, 'owner:1');
            check(false, "a close of $f to $t was accepted");
        } catch (LedgerError) {
        }
    }
    check_refused(fn() => $pdo->exec("UPDATE ledger.closes SET note = 'edited' WHERE id = $c"), 'a signed close was edited');
    check_refused(fn() => $pdo->exec("DELETE FROM ledger.closes WHERE id = $c"), 'a signed close was deleted');
    // Unlocking is trusted code only.
    try {
        ledger_unlock($pdo, $b, null, 'owner:1', 'want to change January');
        check(false, 'a tenant unlocked a closed period');
    } catch (LedgerError) {
    }
    check_same(1, count(ledger_closes($pdo, $b)), 'the closes list');
}

function test_ledger_earlier_years_and_current_year_earnings(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('fiscal');
    ledger_post($pdo, $b, ['entry_on' => '2025-12-15', 'lines' => [['account' => 'cash.operating', 'debit' => 30000], ['account' => 'revenue.services', 'credit' => 30000]]]);
    ledger_post($pdo, $b, ['entry_on' => '2026-01-15', 'lines' => [['account' => 'cash.operating', 'debit' => 7000], ['account' => 'revenue.services', 'credit' => 7000]]]);
    $bs = ledger_balance_sheet($pdo, $b, 'accrual', '2026-01-31');
    check_same(7000, $bs['current_year_earnings'], 'current-year earnings from January');
    check_same(37000, $bs['total_equity'], 'earlier earnings carried in equity');
    check($bs['balanced'], 'the balance sheet does not balance across the year end');
    // A fiscal year starting in July: December and January are the same year.
    $pdo->exec("UPDATE ledger.books SET fiscal_year_start = 7 WHERE id = $b");
    check_same(37000, ledger_balance_sheet($pdo, $b, 'accrual', '2026-01-31')['current_year_earnings'], 'July fiscal year');
}

function test_ledger_fixed_assets_depreciate_change_and_dispose(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('assets');
    ledger_post($pdo, $b, ['entry_on' => '2026-01-01', 'lines' => [['account' => 'cash.operating', 'debit' => 1000000], ['account' => 'equity.capital', 'credit' => 1000000]]]);
    // $3,600 over 36 months, no salvage: $100 a month from January.
    $id = ledger_asset_add($pdo, $b, ['name' => 'Copier', 'cost' => 360000, 'in_service_on' => '2026-01-15', 'life_months' => 36,
        'source_key' => 'asset:copier', 'paid_from' => 'cash.operating']);
    check_same($id, ledger_asset_add($pdo, $b, ['name' => 'Copier', 'cost' => 1, 'in_service_on' => '2026-01-15', 'life_months' => 1, 'source_key' => 'asset:copier']), 'an asset was added twice');
    check_same(3, ledger_depreciate_through($pdo, $b, '2026-03-31'), 'three months posted');
    check_same(0, ledger_depreciate_through($pdo, $b, '2026-03-31'), 'depreciation posted twice');
    check_same(30000, ledger_balance($pdo, $b, 'exp.depreciation', 'cash'), 'depreciation counts on the cash basis too');
    check_same(['Copier'], ledger_assets_behind($pdo, $b, '2026-04-30'), 'April is not flagged as behind');
    // Change of estimate in April: life 24 months, $300 salvage. What is left ($3,300 - $300) spreads over the 21 months left.
    $eff = ledger_asset_change($pdo, $b, $id, ['life_months' => 24, 'salvage' => 30000], 'Heavier use than expected', 'owner');
    check_same('2026-04-01', $eff, 'the change does not apply from the next unposted month');
    $sched = ledger_asset_schedule($pdo, ledger_asset($pdo, $b, $id));
    check_same(21, count($sched), 'months left after the change');
    check_same(300000, array_sum($sched), 'what is left to depreciate after the change');
    check_same(30000, ledger_balance($pdo, $b, 'exp.depreciation', 'accrual', '2026-03-31'), 'posted months moved');
    check_refused(fn() => $pdo->exec("UPDATE ledger.assets SET life_months = 12 WHERE id = $id"), 'a schedule changed without a recorded change');
    check_refused(fn() => $pdo->exec("UPDATE ledger.assets SET cost_cents = 1 WHERE id = $id"), 'an asset cost changed');
    try {
        ledger_asset_change($pdo, $b, $id, ['life_months' => 3], 'too short', 'owner');
        check(false, 'a life shorter than what was already depreciated');
    } catch (LedgerError) {
    }
    // A close needs depreciation posted through its end.
    try {
        ledger_close_period($pdo, $b, '2026-01-01', '2026-04-30', 'owner');
        check(false, 'a close went ahead with depreciation missing');
    } catch (LedgerError $e) {
        check(str_contains($e->getMessage(), 'Depreciation'), 'the close refused for another reason: ' . $e->getMessage());
    }
    // Sold in July for $2,500: April to June depreciated first ($3,000 / 21 = 142.86 a month).
    ledger_asset_dispose($pdo, $b, $id, '2026-07-10', 250000, 'cash.operating', 'owner');
    $a = ledger_asset($pdo, $b, $id);
    [$accum] = ledger_asset_posted($pdo, $a);
    check_same(30000 + 3 * 14286, $accum, 'depreciation through June');
    $bookValue = 360000 - $accum;
    check_same($bookValue - 250000, ledger_balance($pdo, $b, 'exp.disposal_loss', 'accrual'), 'the loss on disposal');
    check_same(0, ledger_balance($pdo, $b, 'fixed.equipment', 'accrual') + ledger_balance($pdo, $b, 'fixed.accum_depr', 'accrual'), 'the asset is still on the books');
    check_same([], ledger_tie_outs($pdo, $b, 'accrual', '2026-01-01', '2026-07-31'), 'tie-outs with assets');
    check_same([], ledger_tie_outs($pdo, $b, 'cash', '2026-01-01', '2026-07-31'), 'cash tie-outs with assets');
    $reg = ledger_asset_register($pdo, $b);
    check_same([1, '2026-07-10', 1], [count($reg), (string) $reg[0]['disposed_on'], count($reg[0]['changes'])], 'the register');
}

function test_ledger_declining_balance_lands_on_salvage(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('ddb');
    $id = ledger_asset_add($pdo, $b, ['name' => 'Laptops', 'cost' => 500000, 'salvage' => 50000, 'in_service_on' => '2025-01-01', 'life_months' => 36,
        'method' => 'declining_balance', 'source_key' => 'asset:laptops', 'paid_from' => 'cash.operating']);
    $sched = ledger_asset_schedule($pdo, ledger_asset($pdo, $b, $id));
    check_same(36, count($sched), 'a declining-balance schedule runs the full life');
    check_same(450000, array_sum($sched), 'declining balance stops at salvage');
    check(reset($sched) > end($sched), 'declining balance does not decline');
}

function test_ledger_cash_forecast_windows_bands_and_risks(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('forecast');
    ledger_account_ensure($pdo, $b, ['1060', 'Held for others', 'asset', 'Restricted cash: client trust', 'restricted_cash', ['key' => 't.held_cash']]);
    ledger_account_ensure($pdo, $b, ['2550', 'Funds held for others', 'liability', 'Other current liabilities', 'operating', ['key' => 't.held', 'control' => 'trust_liability']]);
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [['account' => 'cash.operating', 'debit' => 1000000], ['account' => 'equity.capital', 'credit' => 1000000]]]);
    // Money held for clients never counts as cash to spend.
    ledger_post($pdo, $b, ['entry_on' => '2026-05-20', 'lines' => [['account' => 't.held_cash', 'debit' => 5000000], ['account' => 't.held', 'credit' => 5000000, 'party' => 'client:9']]]);
    // Client A has paid three invoices, each 15 days late.
    foreach (['01', '02', '03'] as $i => $mo) {
        $doc = ledger_document_issue($pdo, $b, ['kind' => 'receivable', 'party' => 'client:A', 'ref' => "Old $mo", 'issued_on' => "2026-$mo-01", 'due_on' => "2026-$mo-10",
            'source_key' => "old:$mo", 'lines' => [['account' => 'revenue.services', 'amount' => 100000]]]);
        ledger_settle($pdo, $b, $doc, ['settled_on' => "2026-$mo-25", 'amount' => 100000, 'source_key' => "oldpay:$mo"]);
    }
    // Open now: A owes $5,000 due June 25 (expected July 10); B owes $2,000 due June 21 (no history); a bill of $8,000 due June 21.
    ledger_document_issue($pdo, $b, ['kind' => 'receivable', 'party' => 'client:A', 'ref' => 'Inv A', 'issued_on' => '2026-05-25', 'due_on' => '2026-06-25',
        'source_key' => 'inv:a', 'lines' => [['account' => 'revenue.services', 'amount' => 500000]]]);
    ledger_document_issue($pdo, $b, ['kind' => 'receivable', 'party' => 'client:B', 'ref' => 'Inv B', 'issued_on' => '2026-05-22', 'due_on' => '2026-06-21',
        'source_key' => 'inv:b', 'lines' => [['account' => 'revenue.services', 'amount' => 200000]]]);
    ledger_document_issue($pdo, $b, ['kind' => 'payable', 'party' => 'vendor:rent-co', 'ref' => 'Bill 9', 'issued_on' => '2026-05-30', 'due_on' => '2026-06-21',
        'source_key' => 'bill:9', 'lines' => [['account' => 'exp.office', 'amount' => 800000]]]);
    $f = ledger_cash_forecast($pdo, $b, '2026-06-01', [['name' => 'Rent', 'amount' => 300000, 'day' => 1]]);
    check_same(1300000, $f['opening'], 'opening cash: $10,000 in plus $3,000 collected, and no client trust money');
    check_same([200000, 500000, 0], array_column($f['windows'], 'in'), 'inflows by window');
    check_same([800000 + 300000 + 300000, 0, 300000], array_column($f['windows'], 'out'), 'outflows by window (rent on the 1st)');
    check_same(30.0, $f['windows'][0]['band_pct'], 'a client with no history gets the default band');
    check_same(0.0, $f['windows'][1]['band_pct'], 'a client who always pays 15 days late has no spread');
    check_same(1300000 + 200000 - 1400000, $f['windows'][0]['cash_end'], 'cash at the end of the first window');
    $kinds = array_column($f['risks'], 'kind');
    check(in_array('late_payer', $kinds, true) && in_array('thin_data', $kinds, true), 'risks: ' . json_encode($kinds));
}

function test_ledger_bank_reconciliation(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('bank');
    $post = fn(string $on, int $amt, string $other) => ledger_post($pdo, $b, ['entry_on' => $on, 'lines' => [
        ['account' => 'cash.operating', 'debit' => $amt], ['account' => $other, 'credit' => $amt]]]);
    $post('2026-03-01', 1000000, 'equity.capital');        // $10,000 in
    $post('2026-03-10', -120000, 'exp.rent');               // check 101, $1,200 out (negative debit = credit)
    $post('2026-03-30', -40000, 'exp.software');            // check 102, not yet cleared
    $post('2026-03-31', 250000, 'revenue.services');        // deposit, in transit
    // The bank: the $10,000, check 101, a $25 fee and $3 interest. Closing $8,778.
    [$added, $dupes] = ledger_bank_import($pdo, $b, 'cash.operating', '2026-03-01', '2026-03-31', 877800, [
        ['2026-03-02', 1000000, 'DEPOSIT', '', 'b1'], ['2026-03-12', -120000, 'CHECK 101', '101', 'b2'],
        ['2026-03-31', -2500, 'SERVICE FEE', '', 'b3'], ['2026-03-31', 300, 'INTEREST', '', 'b4']]);
    check_same([4, 0], [$added, $dupes], 'import');
    check_same([0, 4], ledger_bank_import($pdo, $b, 'cash.operating', '2026-03-01', '2026-03-31', 877800, [
        ['2026-03-02', 1000000, 'DEPOSIT', '', 'b1'], ['2026-03-12', -120000, 'CHECK 101', '101', 'b2'],
        ['2026-03-31', -2500, 'SERVICE FEE', '', 'b3'], ['2026-03-31', 300, 'INTEREST', '', 'b4']]), 'a second import added lines');
    check_same(2, ledger_bank_auto_match($pdo, $b, 'cash.operating', 'tester'), 'auto-matched the deposit and check 101');
    $r = ledger_bank_reconciliation($pdo, $b, 'cash.operating', '2026-03-31');
    check(!$r['reconciled'], 'reconciled with bank-only items unposted');
    check_same(2, count($r['bank_only']), 'the fee and interest are bank-only');
    foreach ($r['bank_only'] as $bl) {
        ledger_bank_post_line($pdo, $b, (int) $bl['id'], (int) $bl['amount_cents'] < 0 ? 'exp.bank_fees' : 'revenue.other', 'tester');
    }
    $r = ledger_bank_reconciliation($pdo, $b, 'cash.operating', '2026-03-31');
    check_same([877800, 250000, 40000, 1087800, 1087800], [$r['bank_closing'], $r['in_transit'], $r['outstanding'], $r['adjusted_bank'], $r['books']], 'the reconciliation');
    check($r['reconciled'], 'it does not reconcile');
    // A match is for the same account and amount only.
    $bl = ledger_bank_import($pdo, $b, 'cash.operating', '2026-04-01', '2026-04-30', null, [['2026-04-02', 250000, 'DEPOSIT', '', 'b5']]);
    $lineId = (int) $pdo->query("SELECT id FROM ledger.bank_lines WHERE bank_id = 'b5'")->fetchColumn();
    $wrong = (int) $pdo->query("SELECT l.id FROM ledger.lines l JOIN ledger.accounts a ON a.id = l.account_id WHERE a.book_id = $b AND a.system_key = 'exp.rent' LIMIT 1")->fetchColumn();
    check_refused(fn() => $pdo->exec("UPDATE ledger.bank_lines SET matched_line_id = $wrong WHERE id = $lineId"), 'a bank line matched another account');
    check_refused(fn() => $pdo->exec("UPDATE ledger.bank_lines SET amount_cents = 1 WHERE id = $lineId"), 'a bank line was edited');
    check_same(1, ledger_bank_auto_match($pdo, $b, 'cash.operating', 'tester'), 'the March 31 deposit matched in April');
}

function test_ledger_year_end_close_moves_earnings_without_double_counting(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('yearend');
    ledger_book_set_entity($pdo, $b, 'corporation', 'c_corp');
    ledger_post($pdo, $b, ['entry_on' => '2025-06-01', 'lines' => [['account' => 'cash.operating', 'debit' => 500000], ['account' => 'equity.stock', 'credit' => 500000]]]);
    ledger_post($pdo, $b, ['entry_on' => '2025-07-01', 'lines' => [['account' => 'cash.operating', 'debit' => 300000], ['account' => 'revenue.services', 'credit' => 300000]]]);
    ledger_post($pdo, $b, ['entry_on' => '2025-08-01', 'lines' => [['account' => 'exp.rent', 'debit' => 100000], ['account' => 'cash.operating', 'credit' => 100000]]]);
    $inv = lt_invoice($pdo, $b, 'inv:ye', '2025-12-15', [['revenue.services', 50000]]);   // unpaid at year end: accrual only
    try {
        ledger_year_end_close($pdo, $b, '2025-11-30', 'owner');
        check(false, 'a year end that is not one');
    } catch (LedgerError) {
    }
    $ids = ledger_year_end_close($pdo, $b, '2025-12-31', 'owner');
    check_same($ids, ledger_year_end_close($pdo, $b, '2025-12-31', 'owner'), 'the year was closed twice');
    // Accrual earned $2,500; cash $2,000. Each basis closes its own.
    check_same(-250000, ledger_balance($pdo, $b, 'equity.retained', 'accrual'), 'accrual retained earnings');
    check_same(-200000, ledger_balance($pdo, $b, 'equity.retained', 'cash'), 'cash retained earnings');
    // The year's P&L still shows what it earned; the cash flow is unchanged by the close.
    check_same(250000, ledger_profit_and_loss($pdo, $b, 'accrual', '2025-01-01', '2025-12-31')['net_income'], 'P&L after the close');
    check_same(200000, ledger_cash_flow($pdo, $b, 'cash', '2025-01-01', '2025-12-31')['net_income'], 'cash flow after the close');
    // Equity is not counted twice.
    $bs = ledger_balance_sheet($pdo, $b, 'accrual', '2025-12-31');
    check($bs['balanced'], 'the balance sheet does not balance after the close');
    check_same(750000, $bs['total_equity'], 'equity after the close: $5,000 stock + $2,500 earned');
    check_same(750000, ledger_balance_sheet($pdo, $b, 'accrual', '2026-01-31')['total_equity'], 'equity in the new year');
    $eq = ledger_equity_statement($pdo, $b, 'accrual', '2025-01-01', '2025-12-31');
    check_same([250000, 750000], [$eq['totals']['net_income'], $eq['totals']['closing']], 'the equity statement for the year');
    foreach (['accrual', 'cash'] as $basis) {
        check_same([], ledger_tie_outs($pdo, $b, $basis, '2025-01-01', '2025-12-31'), "tie-outs for the closed year, $basis");
        check_same([], ledger_tie_outs($pdo, $b, $basis, '2025-12-01', '2026-01-31'), "tie-outs across the year end, $basis");
    }
}
