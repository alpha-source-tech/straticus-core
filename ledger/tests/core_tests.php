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
