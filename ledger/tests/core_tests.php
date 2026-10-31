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

    // Locked through January (by a signed close): nothing is dated in it, the engine posts on the first open day.
    ledger_close_period($pdo, $b, '2026-01-01', '2026-01-31', 'owner');
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

function test_ledger_partnership_year_end_splits_by_share(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('partners');
    ledger_book_set_entity($pdo, $b, 'pllc', 'partnership');
    try {
        ledger_profit_shares_set($pdo, $b, '2025-01-01', ['Ana' => '60', 'Ben' => '30'], 'owner');
        check(false, 'shares that add up to 90% were accepted');
    } catch (LedgerError) {
    }
    ledger_profit_shares_set($pdo, $b, '2025-01-01', ['Ana' => '60', 'Ben' => '40'], 'owner');
    ledger_profit_shares_set($pdo, $b, '2026-01-01', ['Ana' => '50', 'Ben' => '50'], 'owner');
    check_same(['Ana' => 6000, 'Ben' => 4000], ledger_profit_shares($pdo, $b, '2025-12-31'), 'the 2025 shares');
    ledger_post($pdo, $b, ['entry_on' => '2025-05-01', 'lines' => [['account' => 'cash.operating', 'debit' => 100001], ['account' => 'revenue.services', 'credit' => 100001]]]);
    ledger_year_end_close($pdo, $b, '2025-12-31', 'owner');
    $ana = ledger_partner_capital($pdo, $b, 'Ana');
    $ben = ledger_partner_capital($pdo, $b, 'Ben');
    check_same([-60001, -40000], [ledger_balance($pdo, $b, $ana, 'accrual'), ledger_balance($pdo, $b, $ben, 'accrual')], 'each partner takes their share (the odd cent to the larger)');
    $eq = ledger_equity_statement($pdo, $b, 'accrual', '2025-01-01', '2025-12-31');
    check_same(100001, $eq['totals']['closing'], 'equity after the split');
    check(in_array('Ana', array_column($eq['rows'], 'owner'), true), 'the equity statement does not show each partner');
    check_same([], ledger_tie_outs($pdo, $b, 'accrual', '2025-01-01', '2025-12-31'), 'tie-outs after the split');
}


function test_ledger_review_fixes_hold(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('review');
    ledger_post($pdo, $b, ['entry_on' => '2026-01-05', 'lines' => [['account' => 'cash.operating', 'debit' => 100], ['account' => 'revenue.services', 'credit' => 100]]]);
    $cash = ledger_account_id($pdo, $b, 'cash.operating');
    check_refused(fn() => $pdo->exec("UPDATE ledger.accounts SET cash_flow_class = 'operating' WHERE id = $cash"), 'a posted account changed its cash flow class');
    $pdo->exec("UPDATE ledger.accounts SET name = 'Checking' WHERE id = $cash");   // a rename is fine
    $doc = ledger_document_issue($pdo, $b, ['kind' => 'receivable', 'party' => 'c:1', 'ref' => 'I1', 'issued_on' => '2026-02-01', 'source_key' => 'i1', 'lines' => [['account' => 'revenue.services', 'amount' => 5000]]]);
    $s = ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-02-10', 'amount' => 5000, 'source_key' => 'p1']);
    check_refused(fn() => $pdo->exec("UPDATE ledger.settlements SET reversed_at = now(), reverse_reason = 'direct' WHERE id = $s"), 'a payment was marked reversed without reversing its entries');
    ledger_settlement_reverse($pdo, $b, $s, '2026-02-11', 'tester', 'bounced');   // the proper way still works
    check_same(5000, ledger_document_open($pdo, $doc), 'the proper reversal');
    check_refused(fn() => $pdo->exec("UPDATE ledger.books SET locked_through = '2030-01-01' WHERE id = $b"), 'the lock moved without a close');
    ledger_close_period($pdo, $b, '2026-01-01', '2026-01-31', 'owner');   // and the proper close still locks
    check_same('2026-01-31', (string) $pdo->query("SELECT locked_through FROM ledger.books WHERE id = $b")->fetchColumn(), 'the close did not lock');
}

function test_ledger_review_followups(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('followups');
    // Accrual-only accounts take accrual-basis lines only.
    try {
        ledger_post($pdo, $b, ['entry_on' => '2026-01-31', 'basis' => 'both', 'lines' => [['account' => 'exp.rent', 'debit' => 100], ['account' => 'accrued', 'credit' => 100]]]);
        check(false, 'an accrual was posted on both bases');
    } catch (LedgerError $e) {
        check(str_contains($e->getMessage(), 'accrual'), 'wrong refusal: ' . $e->getMessage());
    }
    ledger_post($pdo, $b, ['entry_on' => '2026-01-31', 'basis' => 'accrual', 'lines' => [['account' => 'exp.rent', 'debit' => 100], ['account' => 'accrued', 'credit' => 100]]]);
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [['account' => 'cash.operating', 'debit' => 5000], ['account' => 'equity.capital', 'credit' => 5000]]]);
    // Renaming an account does not break a signed close.
    $c = ledger_close_period($pdo, $b, '2026-01-01', '2026-01-31', 'owner');
    $rent = ledger_account_id($pdo, $b, 'exp.rent');
    $pdo->exec("UPDATE ledger.accounts SET name = 'Office rent', code = '6105' WHERE id = $rent");
    ledger_cache_clear();
    check(ledger_close_verify($pdo, $b, $c), 'a rename broke a signed close');
    // A partnership's year-end allocation has its own column; "put in" stays for money put in.
    ledger_book_set_entity($pdo, $b, 'pllc', 'partnership');
    ledger_profit_shares_set($pdo, $b, '2026-01-01', ['Ana' => '50', 'Ben' => '50'], 'owner');
    ledger_post($pdo, $b, ['entry_on' => '2026-06-01', 'lines' => [['account' => 'cash.operating', 'debit' => 20100], ['account' => 'revenue.services', 'credit' => 20100]]]);
    ledger_year_end_close($pdo, $b, '2026-12-31', 'owner');
    $eq = ledger_equity_statement($pdo, $b, 'accrual', '2026-01-01', '2026-12-31');
    $ana = array_values(array_filter($eq['rows'], fn($r) => $r['owner'] === 'Ana'))[0];
    check_same([0, 10000], [$ana['contributions'], $ana['allocated']], 'Ana\'s allocation shown as money put in');
    check_same([5000, 0, 20000], [$eq['totals']['contributions'], $eq['totals']['allocated'], $eq['totals']['net_income']], 'equity totals');
    check_same([], ledger_tie_outs($pdo, $b, 'accrual', '2026-01-01', '2026-12-31'), 'tie-outs after the follow-ups');
}

/** Six months of a steady practice: $8,000 a month billed Jan–Mar, $10,000 Apr–Jun, each paid on the 20th; rent $3,000 on the 5th. */
function lt_steady_practice(PDO $pdo, int $b): void
{
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [['account' => 'cash.operating', 'debit' => 2000000], ['account' => 'equity.capital', 'credit' => 2000000]]]);
    foreach (['01' => 800000, '02' => 800000, '03' => 800000, '04' => 1000000, '05' => 1000000, '06' => 1000000] as $mo => $fee) {
        $doc = lt_invoice($pdo, $b, "inv:$mo", "2026-$mo-01", [['revenue.services', $fee]], 'client:' . ((int) $mo % 3 + 1));
        ledger_settle($pdo, $b, $doc, ['settled_on' => "2026-$mo-20", 'amount' => $fee, 'source_key' => "pay:$mo"]);
        ledger_post($pdo, $b, ['entry_on' => "2026-$mo-05", 'lines' => [['account' => 'exp.rent', 'debit' => 300000], ['account' => 'cash.operating', 'credit' => 300000]]]);
    }
}

function test_ledger_health_findings_from_hand_worked_books(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('health-good');
    lt_steady_practice($pdo, $b);
    $p = ledger_financial_profile($pdo, $b, '2026-07-15');
    check_same(6, $p['months_with_activity'], 'months with activity');
    check_same(3000000, $p['revenue_3m'], 'revenue April to June');
    check_same(900000, $p['expenses_3m'], 'expenses April to June');
    check_same(70.0, $p['net_margin_pct'], 'net margin: 21,000 / 30,000');
    check_same(25.0, $p['revenue_growth_pct'], 'revenue growth: 30,000 against 24,000');
    check_same(0.0, $p['expense_growth_pct'], 'expenses flat');
    check_same(5600000, $p['cash'], 'cash: 20,000 + 54,000 collected - 18,000 rent');
    check_same(0, $p['monthly_cash_burn'], 'not burning cash');
    check_same(null, $p['runway_months'], 'no runway when not burning');
    check_same(18.7, $p['cash_cover_months'], 'cash covers 56,000 / 3,000 months of costs');
    check_same(0, $p['ar']['total'], 'nothing owed');
    check_same(3000000, $p['collected_90d'], 'collected April 20, May 20, June 20');
    $f = ledger_findings($p, ['days_since_bank' => 10, 'days_since_close' => 10]);
    check_same(['cash_buffer_ok', 'healthy_margin'], array_column($f, 'code'), 'a healthy practice gets only good findings');

    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('health-bad');
    // $5,000 billed on the 1st of April, May and June, due on the 15th; only June's is paid. Rent is $6,000.
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [['account' => 'cash.operating', 'debit' => 1500000], ['account' => 'equity.capital', 'credit' => 1500000]]]);
    foreach (['04', '05', '06'] as $mo) {
        $doc = ledger_document_issue($pdo, $b, ['kind' => 'receivable', 'party' => 'client:1', 'ref' => "Inv $mo", 'issued_on' => "2026-$mo-01", 'due_on' => "2026-$mo-15",
            'source_key' => "inv:$mo", 'lines' => [['account' => 'revenue.services', 'amount' => 500000]]]);
        if ($mo === '06') {
            ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-06-20', 'amount' => 500000, 'source_key' => 'pay:06']);
        }
        ledger_post($pdo, $b, ['entry_on' => "2026-$mo-05", 'lines' => [['account' => 'exp.rent', 'debit' => 600000], ['account' => 'cash.operating', 'credit' => 600000]]]);
    }
    $p = ledger_financial_profile($pdo, $b, '2026-07-15');
    check_same(-300000, $p['net_3m'], 'lost $3,000 over the quarter');
    check_same(200000, $p['cash'], 'cash: 15,000 + 5,000 - 18,000');
    check_same(433333, $p['monthly_cash_burn'], 'cash burn: (6,000 + 6,000 + 1,000) / 3');
    check_same(0.5, $p['runway_months'], 'runway: 2,000 / 4,333');
    check_same(['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 500000, 'over_90' => 500000, 'total' => 1000000], $p['ar'], 'aging: April 91 days late, May 61');
    check_same(90, $p['dso_days'], 'DSO: 10,000 owed / (10,000 billed / 90 days)');
    check_same(50.0, $p['collection_rate_pct'], 'collected 5,000 of 10,000 billed in 90 days');
    $f = ledger_findings($p);
    $codes = array_column($f, 'code');
    foreach (['losing_money', 'cash_burn', 'old_receivables', 'slow_collections', 'low_collection_rate', 'bank_not_reconciled', 'period_not_closed'] as $c) {
        check(in_array($c, $codes, true), "finding $c missing: " . json_encode($codes));
    }
    check_same('critical', $f[0]['severity'], 'most serious first');
    check(!in_array('healthy_margin', $codes, true) && !in_array('cash_buffer_ok', $codes, true), 'no good news for a struggling practice');
    $burn = $f[array_search('cash_burn', $codes, true)];
    check_same('critical', $burn['severity'], 'under three months of runway is critical');
    check(str_contains($burn['advice'], '$2,000.00') && str_contains($burn['advice'], '$10,000.00'), 'advice quotes the measured figures: ' . $burn['advice']);
    $books = ledger_findings($p, ['tie_out_problems' => ['trust does not tie']]);
    check_same('books_do_not_tie', $books[0]['code'], 'books that do not tie come first');

    // Thin data says so, rather than judging a trend.
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('health-thin');
    ledger_post($pdo, $b, ['entry_on' => '2026-06-02', 'lines' => [['account' => 'cash.operating', 'debit' => 100000], ['account' => 'equity.capital', 'credit' => 100000]]]);
    $codes = array_column(ledger_findings(ledger_financial_profile($pdo, $b, '2026-07-15'), ['days_since_bank' => 1, 'days_since_close' => 1]), 'code');
    check_same(['thin_data'], $codes, 'a new practice gets only the thin-data note');
}

function test_ledger_feasibility_verdicts_from_hand_worked_books(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('feasible');
    lt_steady_practice($pdo, $b);
    $on = '2026-07-15';
    // A hire at $60,000 + 20% = $6,000 a month from August 1: payroll on Aug 15, Aug 31, Sep 15, Sep 30 in the 90 days.
    $r = ledger_feasibility($pdo, $b, $on, ['kind' => 'hire', 'annual_salary' => 6000000, 'starts_on' => '2026-08-01']);
    check_same('recommended', $r['verdict'], 'an affordable hire: ' . json_encode($r['reasons']));
    check_same(-600000, $r['figures']['monthly_change'], 'hire cost a month');
    check_same(100000, $r['figures']['avg_monthly_net_after'], 'net after: 7,000 - 6,000');
    check_same(10.0, $r['figures']['net_margin_pct_after'], 'margin after: 1,000 / 10,000');
    // Usual collections ($10,000 a month, cash basis) are expected from Aug 14, on the 28th, at the low band of 70%:
    // 56,000 - 3,000 (Aug 15) is the low point before $7,000 arrives on Aug 28.
    check_same(1000000, $r['figures']['expected_monthly_collections'], 'run-rate collections');
    check_same(5300000, $r['figures']['lowest_cash_90d_after'], 'lowest cash: 56,000 - 3,000 on Aug 15');
    // At $90,000 the practice would lose money every month.
    $r = ledger_feasibility($pdo, $b, $on, ['kind' => 'hire', 'annual_salary' => 9000000, 'starts_on' => '2026-08-01']);
    check_same('not_recommended', $r['verdict'], 'an unaffordable hire');
    check_same(-200000, $r['figures']['avg_monthly_net_after'], 'net after: 7,000 - 9,000');
    // Purchases: one the cash covers with room, one that eats the buffer, one it cannot cover.
    $buy = fn(int $c) => ledger_feasibility($pdo, $b, $on, ['kind' => 'one_time_purchase', 'amount' => $c, 'on' => '2026-08-01', 'name' => 'Server']);
    check_same('recommended', $buy(5000000)['verdict'], 'leaves 6,000, over a month of costs');
    check_same('caution', $buy(5400000)['verdict'], 'leaves 2,000, under a month of costs (3,000)');
    $r = $buy(6000000);
    check_same('not_recommended', $r['verdict'], 'cash would go negative');
    check_same(-400000, $r['figures']['lowest_cash_90d_after'], 'lowest cash after the purchase');
    // A loan: $10,000 in on July 20, $1,000 out on Aug 20 and Sep 20.
    $r = ledger_feasibility($pdo, $b, $on, ['kind' => 'loan', 'principal' => 1000000, 'monthly_payment' => 100000, 'on' => '2026-07-20']);
    check_same('recommended', $r['verdict'], 'a small loan');
    check_same(5600000, $r['figures']['lowest_cash_90d_after'], 'the low point is today, before the loan arrives');
    check_same(8400000, end($r['forecast_after'])['cash_end'], 'cash after 90 days: 56,000 + 10,000 loan - 2,000 payments + 2 x 10,000 usual collections');
    // No cash in the books yet: an unaffordable hire stays not recommended (it is not softened to caution).
    ['pdo' => $pdo2, 'book' => $b2] = ledger_test_book('feasible-nocash');
    foreach (['04', '05', '06'] as $mo) {
        lt_invoice($pdo2, $b2, "nc:$mo", "2026-$mo-01", [['revenue.services', 1000000]]);
        ledger_document_issue($pdo2, $b2, ['kind' => 'payable', 'party' => 'vendor:land', 'ref' => "Rent $mo", 'issued_on' => "2026-$mo-01", 'source_key' => "ncb:$mo",
            'lines' => [['account' => 'exp.rent', 'amount' => 300000]]]);
    }
    $r = ledger_feasibility($pdo2, $b2, $on, ['kind' => 'hire', 'annual_salary' => 9000000, 'starts_on' => '2026-08-01']);
    check(null === $r['figures']['lowest_cash_90d_after'], 'no cash balance');
    check_same('not_recommended', $r['verdict'], 'no cash balance does not soften a losing hire: ' . json_encode($r['reasons']));
    foreach ([['kind' => 'hire'], ['kind' => 'yacht'], ['kind' => 'one_time_purchase', 'amount' => -5]] as $bad) {
        try {
            ledger_feasibility($pdo, $b, $on, $bad);
            check(false, 'an incomplete scenario was judged: ' . json_encode($bad));
        } catch (LedgerError) {
        }
    }
}

function test_ledger_startup_opens_at_zero_with_a_contribution(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('startup');
    check_same(false, ledger_origin($pdo, $b)['ready'], 'new books are not set up');
    ledger_book_set_entity($pdo, $b, 'single_member_llc', 'sole_proprietor');
    ledger_open_startup($pdo, $b, '2026-03-01', 1000000, 'owner');
    $o = ledger_origin($pdo, $b);
    check_same(['startup', '2026-03-01', true], [$o['origin'], $o['started_on'], $o['ready']], 'origin');
    check_same(1000000, lt_balance($pdo, $b, 'cash.operating', 'accrual'), 'cash from the contribution');
    $bs = ledger_balance_sheet($pdo, $b, 'cash', '2026-03-01');
    check_same(1000000, $bs['total_equity'], 'equity on the cash basis too');
    check_same([], ledger_tie_outs($pdo, $b, 'accrual', '2026-03-01', '2026-03-31'), 'ties out');
    try {
        ledger_open_startup($pdo, $b, '2026-03-01');
        check(false, 'set up twice');
    } catch (LedgerError) {
    }
}

function test_ledger_existing_business_brings_balances_in_and_locks_the_past(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('existing');
    ledger_book_set_entity($pdo, $b, 'pllc', 'sole_proprietor');
    ledger_account_ensure($pdo, $b, ['1060', 'Held for others', 'asset', 'Restricted cash: client trust', 'restricted_cash', ['key' => 't.held_cash']]);
    ledger_account_ensure($pdo, $b, ['2550', 'Funds held for others', 'liability', 'Other current liabilities', 'operating', ['key' => 't.held', 'control' => 'trust_liability']]);
    // The old system's trial balance at 31 Dec: cash 25,000; AR 8,000 (two invoices); equipment 12,000 less 4,000 depreciation;
    // AP 1,500 (one bill); trust 5,000; capital 30,000 and whatever is left is the old retained earnings.
    $r = ledger_open_existing($pdo, $b, '2026-01-01', [
        'balances' => [
            ['account' => 'cash.operating', 'debit' => 2500000],
            ['account' => 'equity.capital', 'credit' => 3000000],
        ],
        'receivables' => [
            ['party' => 'client:1', 'ref' => 'INV-0040', 'issued_on' => '2025-11-15', 'due_on' => '2025-12-15', 'lines' => [['account' => 'revenue.services', 'amount' => 500000]]],
            ['party' => 'client:2', 'ref' => 'INV-0041', 'issued_on' => '2025-12-20', 'lines' => [['account' => 'revenue.services', 'amount' => 300000]]],
        ],
        'payables' => [['party' => 'vendor:1', 'ref' => 'Bill 77', 'issued_on' => '2025-12-28', 'lines' => [['account' => 'exp.office', 'amount' => 150000]]]],
        'assets' => [['name' => 'Server', 'cost' => 1200000, 'in_service_on' => '2024-01-01', 'life_months' => 36, 'accumulated' => 400000]],
        'held' => [['party' => 'client:1', 'amount' => 300000], ['party' => 'client:3', 'amount' => 200000]],
    ], 'owner');
    check_same('2025-12-31', $r['as_of'], 'opening position date');
    check_same(3, $r['documents'], 'documents issued');
    // Assets 25,000 + 8,000 + 12,000 - 4,000 + 5,000 = 46,000; liabilities 1,500 + 5,000 = 6,500; capital 30,000 → retained 9,500 to opening equity.
    check_same(950000, $r['plug'], 'the difference goes to opening-balance equity, and is reported');
    $bs = ledger_balance_sheet($pdo, $b, 'accrual', '2025-12-31');
    check_same(4600000, $bs['total_assets'], 'opening assets');
    check_same(650000, $bs['total_liabilities'], 'opening liabilities');
    check_same(3950000, $bs['total_equity'], 'opening equity');
    check_same(800000, lt_balance($pdo, $b, 'ar', 'accrual', '2025-12-31'), 'receivables from the invoices alone');
    check_same(0, ledger_profit_and_loss($pdo, $b, 'accrual', '2026-01-01', '2026-01-31')['net_income'], 'nothing of the old year leaks into January');
    foreach (['accrual', 'cash'] as $basis) {
        check_same([], ledger_tie_outs($pdo, $b, $basis, '2026-01-01', '2026-01-31'), "$basis ties out");
    }
    $o = ledger_origin($pdo, $b);
    check_same(['existing', '2026-01-01', '2025-12-31'], [$o['origin'], $o['started_on'], $o['locked_through']], 'the past is locked');
    // The old invoice is collected here: cash-basis revenue when paid, in January.
    $doc = (int) $pdo->query("SELECT id FROM ledger.documents WHERE book_id = $b AND ref = 'INV-0040'")->fetchColumn();
    ledger_settle($pdo, $b, $doc, ['settled_on' => '2026-01-10', 'amount' => 500000, 'source_key' => 'pay:40']);
    check_same(300000, lt_balance($pdo, $b, 'ar', 'accrual'), 'receivable after collection');
    check_same(-500000, lt_balance($pdo, $b, 'revenue.services', 'cash'), 'collected old work is cash-basis income when received');
    check_same(0, ledger_profit_and_loss($pdo, $b, 'accrual', '2026-01-01', '2026-01-31')['net_income'], 'and not accrual income again');
    // Depreciation continues from January as a change of estimate: 8,000 of book value left over the 12 months
    // of life remaining = 666.67 a month (the old system had taken less than straight line would).
    ledger_depreciate_through($pdo, $b, '2026-01-31');
    check_same(-466667, lt_balance($pdo, $b, 'fixed.accum_depr', 'accrual'), 'accumulated depreciation after January');
    // Nothing can be dated into the locked past.
    try {
        ledger_post($pdo, $b, ['entry_on' => '2025-12-15', 'lines' => [['account' => 'exp.office', 'debit' => 100], ['account' => 'cash.operating', 'credit' => 100]]]);
        $moved = $pdo->query("SELECT original_on FROM ledger.entries WHERE book_id = $b ORDER BY id DESC LIMIT 1")->fetchColumn();
        check($moved === '2025-12-15', 'an entry dated into the locked past is moved forward and remembered');
    } catch (LedgerError) {
    }
    // Refusals: a revenue balance, a control-account figure.
    ['pdo' => $pdo2, 'book' => $b2] = ledger_test_book('existing-bad');
    foreach ([['account' => 'revenue.services', 'credit' => 100], ['account' => 'ar', 'debit' => 100]] as $bad) {
        try {
            ledger_open_existing($pdo2, $b2, '2026-01-01', ['balances' => [$bad]]);
            check(false, 'accepted ' . json_encode($bad));
        } catch (LedgerError) {
        }
    }
    check_same(false, ledger_origin($pdo2, $b2)['ready'], 'a refused opening leaves the books unset');
    // A trial balance file: control rows come back apart, unknown codes are named.
    $parsed = ledger_opening_parse_csv($pdo2, $b2, "Code,Name,Debit,Credit\n1000,Operating cash,\"25,000.00\",\n1100,Accounts receivable,8000,\n9999,Mystery,10,\n3000,Capital,,30000\nTotal,,33010,30000\n");
    check_same(2, count($parsed['balances']), 'balances parsed');
    check_same('receivable', $parsed['control'][0]['control'], 'the control row is set apart');
    check_same('9999', $parsed['unknown'][0]['account'], 'the unknown code is named');
}

/**
 * Period sums: every read that adds the journal up gives the same figure
 * whether it comes from the month sums, the lines, or both. Hand-placed
 * entries across month edges, both bases, a year-end close and a party.
 */
function test_ledger_period_sums_equal_the_full_journal(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('sums');
    ledger_book_set_entity($pdo, $b, 'corporation', 'c_corp');
    $post = fn(string $on, array $lines, string $basis = 'both') => ledger_post($pdo, $b, ['entry_on' => $on, 'basis' => $basis, 'lines' => $lines]);
    $post('2025-01-01', [['account' => 'cash.operating', 'debit' => 100000], ['account' => 'equity.stock', 'credit' => 100000]]);
    $post('2025-01-31', [['account' => 'cash.operating', 'debit' => 1000], ['account' => 'revenue.services', 'credit' => 1000]]);
    $post('2025-02-01', [['account' => 'cash.operating', 'debit' => 2000], ['account' => 'revenue.services', 'credit' => 2000]]);
    $post('2025-02-14', [['account' => 'exp.rent', 'debit' => 300], ['account' => 'cash.operating', 'credit' => 300]], 'accrual');
    $post('2025-02-14', [['account' => 'exp.rent', 'debit' => 400], ['account' => 'cash.operating', 'credit' => 400]], 'cash');
    $post('2025-03-15', [['account' => 'cash.operating', 'debit' => 5000], ['account' => 'revenue.services', 'credit' => 5000]]);
    lt_invoice($pdo, $b, 'inv:s1', '2025-04-10', [['revenue.services', 7000]], 'client:a');
    lt_invoice($pdo, $b, 'inv:s2', '2025-04-20', [['revenue.services', 9000]], 'client:b');
    $post('2025-12-31', [['account' => 'cash.operating', 'debit' => 1100], ['account' => 'revenue.services', 'credit' => 1100]]);
    ledger_year_end_close($pdo, $b, '2025-12-31', 'owner');
    $post('2026-01-10', [['account' => 'exp.rent', 'debit' => 600], ['account' => 'cash.operating', 'credit' => 600]]);

    // The sums table and the journal agree line for line.
    $q = $pdo->prepare("SELECT coalesce(SUM(debit_cents), 0) || '/' || coalesce(SUM(credit_cents), 0) FROM ledger.period_sums WHERE book_id = :b");
    $q->execute(['b' => $b]);
    $r = $pdo->prepare("SELECT coalesce(SUM(debit_cents), 0) || '/' || coalesce(SUM(credit_cents), 0) FROM ledger.lines WHERE book_id = :b");
    $r->execute(['b' => $b]);
    check_same($r->fetchColumn(), $q->fetchColumn(), 'the sums table drifted from the journal');

    // Every kind of range a statement asks for, against the raw sum.
    $raw = function (string $basis, ?string $from, ?string $to, bool $xc = false, ?string $party = null) use ($pdo, $b): array {
        $q = $pdo->prepare("SELECT l.account_id, SUM(l.debit_cents - l.credit_cents) FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                             WHERE l.book_id = :b AND e.basis IN ('both', :basis) AND (CAST(:f AS date) IS NULL OR e.entry_on >= CAST(:f2 AS date))
                               AND (CAST(:t AS date) IS NULL OR e.entry_on <= CAST(:t2 AS date)) AND (NOT CAST(:xc AS boolean) OR e.event_type <> 'period.year_end')
                               AND (CAST(:p AS text) IS NULL OR l.party = :p2)
                             GROUP BY l.account_id ORDER BY l.account_id");
        $q->execute(['b' => $b, 'basis' => $basis, 'f' => $from, 'f2' => $from, 't' => $to, 't2' => $to, 'xc' => $xc ? 'true' : 'false', 'p' => $party, 'p2' => $party]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    };
    $viaSums = function (string $basis, ?string $from, ?string $to, bool $xc = false, ?string $party = null) use ($pdo, $b): array {
        [$src, $p] = ledger_sum_source($b, $basis, $from, $to, ['exclude_closing' => $xc] + ($party !== null ? ['party' => $party] : []));
        $q = $pdo->prepare("SELECT account_id, SUM(debit_cents - credit_cents) FROM $src s GROUP BY account_id ORDER BY account_id");
        $q->execute($p);
        return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    };
    $ranges = [
        [null, null], [null, '2025-01-31'], [null, '2025-02-13'], [null, '2025-02-14'], [null, '2025-12-31'], [null, '2026-01-09'],
        ['2025-01-01', '2025-01-31'], ['2025-01-15', '2025-01-20'], ['2025-01-15', '2025-01-31'], ['2025-01-31', '2025-02-01'],
        ['2025-02-01', '2025-03-31'], ['2025-02-14', '2025-03-15'], ['2025-02-01', '2025-04-15'], ['2025-01-15', '2025-04-15'],
        ['2025-03-01', '2025-03-01'], ['2025-02-14', '2025-02-14'], ['2024-01-01', '2026-12-31'], ['2025-12-01', '2026-01-31'],
    ];
    foreach (['accrual', 'cash'] as $basis) {
        foreach ($ranges as [$from, $to]) {
            foreach ([false, true] as $xc) {
                check_same($raw($basis, $from, $to, $xc), $viaSums($basis, $from, $to, $xc), "range $basis " . ($from ?? 'start') . '..' . ($to ?? 'now') . ($xc ? ' excluding the close' : ''));
            }
        }
        check_same($raw($basis, null, '2025-04-15', false, 'client:a'), $viaSums($basis, null, '2025-04-15', false, 'client:a'), "one party on $basis");
    }
    // The readers built on it.
    check_same(-24000, ledger_balance($pdo, $b, 'revenue.services', 'accrual', '2025-12-30'), 'balance through a day inside a month (the 31st is not in it)');
    check_same(16000, ledger_balance($pdo, $b, 'ar', 'accrual', null), 'a control account balance (debit-positive, two unpaid invoices)');
    check_same(7000, ledger_balance($pdo, $b, 'ar', 'accrual', null, 'client:a'), 'a party balance');
    check_same(24800, ledger_profit_and_loss($pdo, $b, 'accrual', '2025-01-01', '2025-12-31')['net_income'], 'the year P&L after the close: $251 of revenue less $3 of accrual rent');
    check_same(-600, ledger_profit_and_loss($pdo, $b, 'accrual', '2026-01-01', '2026-01-31')['net_income'], 'January');
    check(ledger_trial_balance($pdo, $b, 'cash')['balanced'], 'the trial balance');
    check(ledger_stamp($pdo, $b) > 0, 'the stamp');
    $before = ledger_stamp($pdo, $b);
    $post('2026-02-01', [['account' => 'exp.rent', 'debit' => 1], ['account' => 'cash.operating', 'credit' => 1]]);
    check(ledger_stamp($pdo, $b) > $before, 'the stamp moves when anything posts');
}

/**
 * Loans: a $20,000 purchase financed at 6% over 36 months. The asset and the
 * debt go on the balance sheet, nothing on the P&L; each payment splits into
 * principal (balance sheet) and interest (P&L); the principal due within a
 * year sits in the current portion; the last payment closes the loan.
 */
function test_ledger_loans_split_principal_interest_and_the_current_portion(): void
{
    ['pdo' => $pdo, 'book' => $b] = ledger_test_book('loans');
    ledger_post($pdo, $b, ['entry_on' => '2026-01-02', 'lines' => [['account' => 'cash.operating', 'debit' => 500000], ['account' => 'equity.capital', 'credit' => 500000]]]);
    // The equipment goes on the register with no cash leaving; the loan pays for it.
    $asset = ledger_asset_add($pdo, $b, ['name' => 'Copier', 'cost' => 2000000, 'in_service_on' => '2026-01-10', 'life_months' => 60, 'source_key' => 'asset:copier']);
    // 6% APR, 36 payments of $608.44: the standard amortisation of $20,000.
    $loan = ledger_loan_open($pdo, $b, ['name' => 'Copier loan', 'lender' => 'Harbor Bank', 'principal' => 2000000, 'apr_bp' => 600, 'payment' => 60844, 'payments_n' => 36,
        'first_payment_on' => '2026-02-10', 'opened_on' => '2026-01-10', 'source_key' => 'loan:copier', 'against' => 'fixed.equipment', 'actor' => 'tester']);
    check_same($loan, ledger_loan_open($pdo, $b, ['source_key' => 'loan:copier']), 'the same loan twice');
    $l = ledger_loan($pdo, $b, $loan);
    $sched = ledger_loan_schedule($l);
    check_same([36, 10000, 50844, 1949156], [count($sched), $sched[0]['interest'], $sched[0]['principal'], $sched[0]['balance']], 'the first payment: $100 interest, $508.44 principal');
    check_same(0, $sched[35]['balance'], 'the last payment clears it');
    $totalInterest = array_sum(array_column($sched, 'interest'));
    check($totalInterest > 180000 && $totalInterest < 195000, 'about $1,900 of interest over the life: ' . $totalInterest);

    // Balance sheet only: equipment up $20,000, debt up $20,000, cash untouched, P&L untouched.
    check_same(2000000, ledger_balance($pdo, $b, 'fixed.equipment'), 'the asset');
    check_same(500000, ledger_balance($pdo, $b, 'cash.operating'), 'no cash moved');
    check_same(2000000, ledger_loan_balance($pdo, $b, $l), 'the debt');
    check_same(0, ledger_profit_and_loss($pdo, $b, 'accrual', '2026-01-01', '2026-01-31')['net_income'], 'nothing on the P&L');
    // The current portion is the principal of the twelve payments due within a year of opening.
    $due12 = array_sum(array_column(array_slice($sched, 0, 12), 'principal'));
    $cur = -ledger_balance($pdo, $b, 'loans_current', 'accrual', null, 'loan:' . $loan);
    check_same($due12, $cur, 'the current portion');
    $bs = ledger_balance_sheet($pdo, $b, 'accrual', '2026-01-31');
    $line = fn(string $sec, string $code) => (int) (array_values(array_filter($bs['sections'][$sec]['rows'], fn($r) => $r['code'] === $code))[0]['amount'] ?? 0);
    check_same([$due12, 2000000 - $due12], [$line('current_liabilities', '2710'), $line('noncurrent_liabilities', '2700')], 'short-term and long-term on the balance sheet');

    // One payment: cash down $608.44, interest $100 on the P&L, principal $508.44 off the debt, current portion re-split.
    ledger_loan_pay($pdo, $b, $loan, ['paid_on' => '2026-02-10', 'amount' => 60844, 'source_key' => 'loanpay:1', 'actor' => 'tester']);
    check_same([500000 - 60844, 1949156], [ledger_balance($pdo, $b, 'cash.operating'), ledger_loan_balance($pdo, $b, $l)], 'cash and debt after one payment');
    check_same(10000, ledger_balance($pdo, $b, 'exp.interest', 'accrual', null, 'loan:' . $loan), 'the interest is a cost');
    check_same(-10000, ledger_profit_and_loss($pdo, $b, 'accrual', '2026-02-01', '2026-02-28')['net_income'], 'February P&L carries only the interest');
    $due12b = array_sum(array_column(array_slice($sched, 1, 12), 'principal'));
    check_same($due12b, -ledger_balance($pdo, $b, 'loans_current', 'accrual', null, 'loan:' . $loan), 'the current portion moved forward a month');
    check_same(2, ledger_loan_next($pdo, $b, $l)['n'], 'the next payment is the second');
    // A payment with the bank's own interest figure.
    ledger_loan_pay($pdo, $b, $loan, ['paid_on' => '2026-03-10', 'amount' => 60844, 'interest' => 9746, 'source_key' => 'loanpay:2']);
    check_same(1949156 - (60844 - 9746), ledger_loan_balance($pdo, $b, $l), 'principal is the payment less the interest given');
    // Overpaying the principal is refused; so is interest above the payment.
    try {
        ledger_loan_pay($pdo, $b, $loan, ['paid_on' => '2026-03-11', 'amount' => 5000000, 'source_key' => 'loanpay:bad']);
        check(false, 'more than the loan owed was accepted');
    } catch (LedgerError) {
    }
    // Pay the rest: the loan closes, the debt is zero on both accounts, the equipment is still on the books.
    foreach (array_slice($sched, 2) as $row) {
        $owed = ledger_loan_balance($pdo, $b, $l);
        ledger_loan_pay($pdo, $b, $loan, ['paid_on' => $row['due_on'], 'amount' => min(60844, $owed + $row['interest']), 'source_key' => 'loanpay:' . $row['n']]);
    }
    $l = ledger_loan($pdo, $b, $loan);
    check($l['closed_at'] !== null && ledger_loan_balance($pdo, $b, $l) === 0, 'repaid and closed');
    check_same(0, -ledger_balance($pdo, $b, 'loans_current', 'accrual', null, 'loan:' . $loan), 'no current portion left');
    check_same(2000000, ledger_balance($pdo, $b, 'fixed.equipment'), 'the asset stays');
    check(ledger_trial_balance($pdo, $b, 'accrual')['balanced'] && ledger_trial_balance($pdo, $b, 'cash')['balanced'], 'both bases balance');
    check_same([], ledger_tie_outs($pdo, $b, 'accrual', '2026-01-01', '2029-12-31'), 'the statements tie out');
    // A payment that does not cover the interest is refused at the door.
    try {
        ledger_loan_open($pdo, $b, ['name' => 'Bad', 'principal' => 1000000, 'apr_bp' => 2400, 'payment' => 10000, 'payments_n' => 120, 'first_payment_on' => '2026-02-01', 'opened_on' => '2026-01-01', 'source_key' => 'loan:bad']);
        check(false, 'a loan that never amortises was accepted');
    } catch (LedgerError $e) {
        check(str_contains($e->getMessage(), 'interest'), 'the refusal names the problem: ' . $e->getMessage());
    }
}
