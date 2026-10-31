<?php
declare(strict_types=1);

/**
 * Loans: debt with a schedule.
 *
 * A payable can be three things at once: a cost, an asset acquired, and
 * debt taken on to pay for it. The ledger keeps them apart:
 *
 *   opening a loan      debit cash (money borrowed) or the asset it paid for;
 *                       credit the loan. Balance sheet only.
 *   a payment           principal debits the loan (balance sheet only);
 *                       interest debits interest expense (profit and loss);
 *                       cash is credited for the whole payment.
 *   the current portion the principal due within twelve months sits in a
 *                       current-liability account, the rest in long-term
 *                       debt; the split is re-posted after every payment
 *                       and whenever a statement is read through a date.
 *
 * Every loan line carries the party "loan:<id>", so one loan's balance is
 * read like any sub-ledger. Interest is simple monthly interest on the
 * remaining balance (APR / 12), the way a bank amortises; with no rate the
 * payment is all principal. The last payment clears whatever is left.
 */

/** The amortisation schedule: one row per payment with due date, interest, principal and the balance after. */
function ledger_loan_schedule(array $loan): array
{
    $bal = (int) $loan['principal_cents'];
    $rate = ((int) $loan['apr_bp']) / 10000 / 12;
    $pay = (int) $loan['payment_cents'];
    $n = (int) $loan['payments_n'];
    $rows = [];
    for ($i = 1; $i <= $n && $bal > 0; $i++) {
        $interest = (int) round($bal * $rate);
        $principal = $i === $n ? $bal : min($bal, $pay - $interest);
        if ($principal <= 0 && $i < $n) {
            throw new LedgerError('The payment does not cover the interest; the loan would never be repaid.');
        }
        $bal -= $principal;
        $rows[] = ['n' => $i, 'due_on' => gmdate('Y-m-d', strtotime($loan['first_payment_on'] . ' +' . ($i - 1) . ' months')),
                   'interest' => $interest, 'principal' => $principal, 'balance' => $bal];
    }
    return $rows;
}

function ledger_loan(PDO $pdo, int $bookId, int $loanId): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.loans WHERE book_id = :b AND id = :id');
    $q->execute(['b' => $bookId, 'id' => $loanId]);
    $l = $q->fetch(PDO::FETCH_ASSOC);
    if (!$l) {
        throw new LedgerError('No such loan in these books.');
    }
    return $l;
}

/** What a loan still owes on a date: both liability accounts for its party. */
function ledger_loan_balance(PDO $pdo, int $bookId, array $loan, ?string $through = null): int
{
    $party = 'loan:' . (int) $loan['id'];
    return -(ledger_balance($pdo, $bookId, (int) $loan['liability_account_id'], 'accrual', $through, $party)
             + ledger_balance($pdo, $bookId, (int) $loan['current_account_id'], 'accrual', $through, $party));
}

/** Principal paid so far, from the ledger (payments debit the loan's accounts with the party). */
function ledger_loan_paid(PDO $pdo, int $bookId, array $loan): int
{
    return (int) $loan['principal_cents'] - ledger_loan_balance($pdo, $bookId, $loan);
}

/** The next scheduled payment not yet covered by principal paid, or null when the loan is repaid. */
function ledger_loan_next(PDO $pdo, int $bookId, array $loan): ?array
{
    $paid = ledger_loan_paid($pdo, $bookId, $loan);
    $cum = 0;
    foreach (ledger_loan_schedule($loan) as $row) {
        $cum += $row['principal'];
        if ($cum > $paid) {
            return $row;
        }
    }
    return null;
}

/** The accounts a loan needs, created in the book if the template did not carry them. */
function ledger_loan_accounts(PDO $pdo, int $bookId): array
{
    return [
        'liability' => ledger_account_ensure($pdo, $bookId, ['2700', 'Loans payable', 'liability', 'Long-term debt', 'financing', ['key' => 'loans']]),
        'current' => ledger_account_ensure($pdo, $bookId, ['2710', 'Current portion of long-term debt', 'liability', 'Other current liabilities', 'financing', ['key' => 'loans_current']]),
        'interest' => ledger_account_ensure($pdo, $bookId, ['7000', 'Interest expense', 'expense', 'Other income and expense', 'none', ['key' => 'exp.interest']]),
    ];
}

/**
 * Open a loan. $l: name, lender, principal, apr_bp, payment, payments_n,
 * first_payment_on, opened_on, source_key, actor, and against: the account
 * the money went to ('cash.operating' for cash borrowed; the asset account
 * for a financed purchase; null when the borrowing is already in the books).
 * Posts the principal and then the current-portion split. Returns the id.
 */
function ledger_loan_open(PDO $pdo, int $bookId, array $l): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $l) {
        $q = $pdo->prepare('SELECT id FROM ledger.loans WHERE book_id = :b AND source_key = :k');
        $q->execute(['b' => $bookId, 'k' => $l['source_key']]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
        $principal = (int) ($l['principal'] ?? 0);
        $payment = (int) ($l['payment'] ?? 0);
        $n = (int) ($l['payments_n'] ?? 0);
        $apr = (int) ($l['apr_bp'] ?? 0);
        if ($principal <= 0 || $payment <= 0 || $n < 1 || $n > 600 || $apr < 0 || $apr > 10000 || trim((string) ($l['name'] ?? '')) === ''
            || !ledger_is_date((string) ($l['first_payment_on'] ?? '')) || !ledger_is_date((string) ($l['opened_on'] ?? ''))) {
            throw new LedgerError('A loan needs a name, a principal, a payment, the number of payments, a rate of 0 to 100%, the day it was taken and the first payment date.');
        }
        if ($payment * $n < $principal) {
            throw new LedgerError('The payments add up to less than the principal.');
        }
        $accts = ledger_loan_accounts($pdo, $bookId);
        $loan = ['principal_cents' => $principal, 'apr_bp' => $apr, 'payment_cents' => $payment, 'payments_n' => $n, 'first_payment_on' => $l['first_payment_on']];
        ledger_loan_schedule($loan);   // refuses a payment that does not cover the interest
        $q = $pdo->prepare('INSERT INTO ledger.loans (book_id, name, lender, principal_cents, apr_bp, payment_cents, payments_n, first_payment_on, opened_on,
                                                      liability_account_id, current_account_id, interest_account_id, source_key)
                            VALUES (:b, :n, :l, :p, :a, :pay, :pn, :f, :o, :la, :ca, :ia, :k) RETURNING id');
        $q->execute(['b' => $bookId, 'n' => mb_substr(trim((string) $l['name']), 0, 120), 'l' => mb_substr(trim((string) ($l['lender'] ?? '')), 0, 120),
                     'p' => $principal, 'a' => $apr, 'pay' => $payment, 'pn' => $n, 'f' => $l['first_payment_on'], 'o' => $l['opened_on'],
                     'la' => $accts['liability'], 'ca' => $accts['current'], 'ia' => $accts['interest'], 'k' => $l['source_key']]);
        $id = (int) $q->fetchColumn();
        if (!empty($l['against'])) {
            ledger_post($pdo, $bookId, [
                'entry_on' => $l['opened_on'], 'basis' => 'both', 'source_key' => $l['source_key'] . '#open', 'event_type' => 'loan.opened',
                'rule' => 'core.loan', 'rule_version' => 1, 'memo' => 'Loan: ' . trim((string) $l['name']), 'actor' => (string) ($l['actor'] ?? ''),
                'lines' => [['account' => $l['against'], 'debit' => $principal], ['account' => $accts['liability'], 'credit' => $principal, 'party' => 'loan:' . $id]],
            ]);
        }
        ledger_loan_reclass($pdo, $bookId, $id, (string) $l['opened_on'], (string) ($l['actor'] ?? ''));
        return $id;
    });
}

/**
 * Record a payment. $p: paid_on, amount (the whole payment), source_key,
 * actor, cash_account ('cash.operating' by default), and interest to say the
 * interest part yourself (a bank statement's figure); otherwise the schedule's
 * next payment gives it, scaled if the amount differs. Principal is the rest.
 */
function ledger_loan_pay(PDO $pdo, int $bookId, int $loanId, array $p): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $loanId, $p) {
        $loan = ledger_loan($pdo, $bookId, $loanId);
        $amount = (int) ($p['amount'] ?? 0);
        $on = (string) ($p['paid_on'] ?? '');
        if ($amount <= 0 || !ledger_is_date($on)) {
            throw new LedgerError('A loan payment needs a date and an amount.');
        }
        $owed = ledger_loan_balance($pdo, $bookId, $loan, null);
        if ($owed <= 0) {
            throw new LedgerError('That loan is repaid.');
        }
        $next = ledger_loan_next($pdo, $bookId, $loan);
        $interest = isset($p['interest']) ? (int) $p['interest'] : (int) ($next['interest'] ?? 0);
        if ($interest < 0 || $interest > $amount) {
            throw new LedgerError('The interest part cannot exceed the payment.');
        }
        $principal = $amount - $interest;
        if ($principal > $owed) {
            throw new LedgerError('That pays more principal than the loan still owes (' . $owed . ' cents).');
        }
        $party = 'loan:' . $loanId;
        // Principal comes out of the current portion first, then long-term.
        $cur = -ledger_balance($pdo, $bookId, (int) $loan['current_account_id'], 'accrual', null, $party);
        $fromCur = min($principal, max(0, $cur));
        $fromLong = $principal - $fromCur;
        $lines = [['account' => $p['cash_account'] ?? 'cash.operating', 'credit' => $amount]];
        if ($fromCur > 0) {
            $lines[] = ['account' => (int) $loan['current_account_id'], 'debit' => $fromCur, 'party' => $party];
        }
        if ($fromLong > 0) {
            $lines[] = ['account' => (int) $loan['liability_account_id'], 'debit' => $fromLong, 'party' => $party];
        }
        if ($interest > 0) {
            $lines[] = ['account' => (int) $loan['interest_account_id'], 'debit' => $interest, 'party' => $party];
        }
        $eid = ledger_post($pdo, $bookId, [
            'entry_on' => $on, 'basis' => 'both', 'source_key' => $p['source_key'], 'event_type' => 'loan.paid', 'rule' => 'core.loan', 'rule_version' => 1,
            'memo' => 'Loan payment: ' . $loan['name'] . ($interest > 0 ? ' (interest ' . $interest . ' cents)' : ''), 'actor' => (string) ($p['actor'] ?? ''), 'lines' => $lines,
        ]);
        if (ledger_loan_balance($pdo, $bookId, $loan) === 0) {
            $pdo->prepare('UPDATE ledger.loans SET closed_at = now() WHERE id = :id')->execute(['id' => $loanId]);
        } else {
            ledger_loan_reclass($pdo, $bookId, $loanId, $on, (string) ($p['actor'] ?? ''));
        }
        return $eid;
    });
}

/**
 * Keep the current portion right: the principal the schedule has falling due
 * within twelve months of $asOf sits in the current account, the rest in
 * long-term. Posts the difference (both bases) dated $asOf; nothing when it
 * already agrees. Returns the entry id or 0.
 */
function ledger_loan_reclass(PDO $pdo, int $bookId, int $loanId, string $asOf, string $actor = ''): int
{
    $loan = ledger_loan($pdo, $bookId, $loanId);
    $party = 'loan:' . $loanId;
    $owed = ledger_loan_balance($pdo, $bookId, $loan, null);
    $paid = (int) $loan['principal_cents'] - $owed;
    $horizon = gmdate('Y-m-d', strtotime($asOf . ' +12 months'));
    $cum = 0;
    $due12 = 0;
    foreach (ledger_loan_schedule($loan) as $row) {
        $before = $cum;
        $cum += $row['principal'];
        if ($cum <= $paid) {
            continue;   // already paid
        }
        if ($row['due_on'] <= $horizon) {
            $due12 += $cum - max($before, $paid);
        }
    }
    $want = min($owed, $due12);
    $cur = -ledger_balance($pdo, $bookId, (int) $loan['current_account_id'], 'accrual', null, $party);
    $delta = $want - $cur;
    if ($delta === 0) {
        return 0;
    }
    return ledger_post($pdo, $bookId, [
        'entry_on' => $asOf, 'basis' => 'both', 'source_key' => $loan['source_key'] . '#reclass:' . $asOf . ':' . $want,
        'event_type' => 'loan.reclassified', 'rule' => 'core.loan', 'rule_version' => 1, 'actor' => $actor,
        'memo' => 'Current portion of ' . $loan['name'] . ' due within a year',
        'lines' => $delta > 0
            ? [['account' => (int) $loan['liability_account_id'], 'debit' => $delta, 'party' => $party], ['account' => (int) $loan['current_account_id'], 'credit' => $delta, 'party' => $party]]
            : [['account' => (int) $loan['current_account_id'], 'debit' => -$delta, 'party' => $party], ['account' => (int) $loan['liability_account_id'], 'credit' => -$delta, 'party' => $party]],
    ]);
}

/** The book's loans with balance, next payment and the current portion, open ones first. */
function ledger_loans(PDO $pdo, int $bookId, bool $openOnly = true): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.loans WHERE book_id = :b' . ($openOnly ? ' AND closed_at IS NULL' : '') . ' ORDER BY closed_at NULLS FIRST, opened_on, id');
    $q->execute(['b' => $bookId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $l['balance'] = ledger_loan_balance($pdo, $bookId, $l);
        $l['current'] = -ledger_balance($pdo, $bookId, (int) $l['current_account_id'], 'accrual', null, 'loan:' . (int) $l['id']);
        $l['next'] = ledger_loan_next($pdo, $bookId, $l);
        $l['interest_paid'] = ledger_balance($pdo, $bookId, (int) $l['interest_account_id'], 'accrual', null, 'loan:' . (int) $l['id']);
        $out[] = $l;
    }
    return $out;
}
