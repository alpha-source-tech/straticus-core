<?php
declare(strict_types=1);

/**
 * Financial analysis: the books read the way an owner, a CPA or a lender
 * would read them, as measured findings with recommendations.
 *
 * Everything here is deterministic arithmetic on posted lines: no model is
 * involved, so the same books always give the same findings. An AI layer
 * may choose which analysis to run and explain the result, but every figure
 * it may state comes from here (see core/agent's number check).
 *
 * Money is integer cents; ratios are rounded to one decimal place. Periods
 * are whole calendar months before the as-of date, so a half-finished
 * month never skews a trend.
 */

// loaded by ledger.php

const LEDGER_SEVERITIES = ['critical' => 0, 'warning' => 1, 'note' => 2, 'good' => 3];

/** The first day of the month $n months before $d's month (0 = $d's month). */
function ledger_month_back(string $d, int $n): string
{
    return gmdate('Y-m-01', strtotime(substr($d, 0, 7) . "-01 -$n month"));
}

/**
 * Revenue, expenses and net income per whole month, oldest first, for the
 * $months full months before $asOf's month. Closing entries are left out.
 */
function ledger_monthly(PDO $pdo, int $bookId, string $basis, string $asOf, int $months): array
{
    $out = [];
    for ($i = $months; $i >= 1; $i--) {
        $from = ledger_month_back($asOf, $i);
        $to = gmdate('Y-m-t', strtotime($from));
        $pl = ledger_profit_and_loss($pdo, $bookId, $basis, $from, $to);
        $rev = $pl['sections']['revenue']['total'] + $pl['sections']['other_income']['total'];
        $exp = $pl['sections']['cogs']['total'] + $pl['sections']['opex']['total'] + $pl['sections']['other_expense']['total'] + $pl['sections']['tax']['total'];
        $out[] = ['month' => substr($from, 0, 7), 'from' => $from, 'to' => $to, 'revenue' => $rev, 'expenses' => $exp,
                  'net' => $pl['net_income'], 'depreciation' => ledger_depreciation_in($pdo, $bookId, $from, $to)];
    }
    return $out;
}

/** Depreciation posted in a period (a non-cash expense, left out of cash burn). */
function ledger_depreciation_in(PDO $pdo, int $bookId, string $from, string $to): int
{
    $q = $pdo->prepare("SELECT coalesce(SUM(l.debit_cents - l.credit_cents), 0) FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                         WHERE l.book_id = :b AND e.event_type = 'asset.depreciated' AND e.entry_on BETWEEN CAST(:f AS date) AND CAST(:t AS date)
                           AND l.debit_cents > 0");
    $q->execute(['b' => $bookId, 'f' => $from, 't' => $to]);
    return (int) $q->fetchColumn();
}

function ledger_pct(int|float $part, int|float $whole): ?float
{
    return $whole == 0 ? null : round($part / $whole * 100, 1);
}

function ledger_avg(array $xs): float
{
    return $xs ? array_sum($xs) / count($xs) : 0.0;
}

/** Open receivables by age past due, at a date. */
function ledger_ar_aging(PDO $pdo, int $bookId, string $asOf): array
{
    $b = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
    $q = $pdo->prepare("SELECT id, coalesce(due_on, issued_on + 30) AS due FROM ledger.documents
                         WHERE book_id = :b AND kind = 'receivable' AND status = 'open' AND issued_on <= :t");
    $q->execute(['b' => $bookId, 't' => $asOf]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $open = ledger_documents_owed_one($pdo, (int) $d['id'], $asOf);
        if ($open <= 0) {
            continue;
        }
        $late = (int) ((strtotime($asOf) - strtotime((string) $d['due'])) / 86400);
        $k = $late <= 0 ? 'current' : ($late <= 30 ? '1_30' : ($late <= 60 ? '31_60' : ($late <= 90 ? '61_90' : 'over_90')));
        $b[$k] += $open;
    }
    $b['total'] = array_sum($b);
    return $b;
}

/** What one document still owed at the end of a day. */
function ledger_documents_owed_one(PDO $pdo, int $docId, string $asOf): int
{
    $q = $pdo->prepare("SELECT d.total_cents
                              - coalesce((SELECT SUM(s.amount_cents) FROM ledger.settlements s JOIN ledger.entries ae ON ae.id = s.accrual_entry_id
                                          WHERE s.document_id = d.id AND ae.entry_on <= CAST(:t1 AS date)
                                            AND NOT EXISTS (SELECT 1 FROM ledger.entries r WHERE r.reverses_id = s.accrual_entry_id AND r.entry_on <= CAST(:t2 AS date))), 0)
                              - coalesce((SELECT SUM(w.amount_cents) FROM ledger.write_offs w JOIN ledger.entries we ON we.id = w.entry_id
                                          WHERE w.document_id = d.id AND we.entry_on <= CAST(:t3 AS date)), 0)
                          FROM ledger.documents d WHERE d.id = :d");
    $q->execute(['d' => $docId, 't1' => $asOf, 't2' => $asOf, 't3' => $asOf]);
    return (int) $q->fetchColumn();
}

/** Spendable cash (class 'cash', never restricted) at the end of a day, on the accrual books. */
function ledger_spendable_cash(PDO $pdo, int $bookId, string $asOf): int
{
    return ledger_cash_balances($pdo, $bookId, 'accrual', $asOf)['cash'];
}

/**
 * The figures every finding and feasibility test draws on, measured once.
 *
 * @return array{as_of:string, months:array, cash:int, monthly_cash_burn:?int, runway_months:?float, ...}
 */
function ledger_financial_profile(PDO $pdo, int $bookId, string $asOf): array
{
    $m12 = ledger_monthly($pdo, $bookId, 'accrual', $asOf, 12);
    $last3 = array_slice($m12, -3);
    $prev3 = array_slice($m12, -6, 3);
    $cash12 = ledger_monthly($pdo, $bookId, 'cash', $asOf, 3);
    $active = array_values(array_filter($m12, fn($m) => $m['revenue'] !== 0 || $m['expenses'] !== 0));
    $rev3 = array_sum(array_column($last3, 'revenue'));
    $exp3 = array_sum(array_column($last3, 'expenses'));
    $revPrev = array_sum(array_column($prev3, 'revenue'));
    $expPrev = array_sum(array_column($prev3, 'expenses'));
    // Cash burn: what the cash-basis books spent more than they took in, a month,
    // over the last three months, with depreciation (no cash) added back.
    $cashNet = ledger_avg(array_map(fn($m) => $m['net'] + $m['depreciation'], $cash12));
    $cash = ledger_spendable_cash($pdo, $bookId, $asOf);
    $burn = $cashNet < 0 ? (int) round(-$cashNet) : 0;
    $monthlyOut = (int) round(ledger_avg(array_map(fn($m) => $m['expenses'] - $m['depreciation'], $cash12)));
    $aging = ledger_ar_aging($pdo, $bookId, $asOf);
    $from90 = gmdate('Y-m-d', strtotime("$asOf -89 days"));
    $billed90 = ledger_billed($pdo, $bookId, $from90, $asOf);
    $collected90 = ledger_collected($pdo, $bookId, $from90, $asOf);
    $from12 = ledger_month_back($asOf, 12);
    $rev12 = array_sum(array_column($m12, 'revenue'));
    $q = $pdo->prepare('SELECT coalesce(SUM(w.amount_cents), 0) FROM ledger.write_offs w WHERE w.book_id = :b AND w.written_on BETWEEN CAST(:f AS date) AND CAST(:t AS date)');
    $q->execute(['b' => $bookId, 'f' => $from12, 't' => $asOf]);
    $writeOffs12 = (int) $q->fetchColumn();
    return [
        'as_of' => $asOf, 'months' => $m12, 'months_with_activity' => count($active),
        'revenue_3m' => $rev3, 'expenses_3m' => $exp3, 'net_3m' => $rev3 - $exp3,
        'revenue_prev_3m' => $revPrev, 'expenses_prev_3m' => $expPrev,
        'revenue_growth_pct' => ledger_pct($rev3 - $revPrev, abs($revPrev)), 'expense_growth_pct' => ledger_pct($exp3 - $expPrev, abs($expPrev)),
        'net_margin_pct' => ledger_pct($rev3 - $exp3, $rev3),
        'avg_monthly_revenue' => (int) round($rev3 / 3), 'avg_monthly_expenses' => (int) round($exp3 / 3),
        'avg_monthly_net' => (int) round(($rev3 - $exp3) / 3),
        'cash' => $cash, 'avg_monthly_cash_out' => $monthlyOut, 'avg_monthly_cash_net' => (int) round($cashNet), 'monthly_cash_burn' => $burn,
        'runway_months' => $burn > 0 ? round($cash / $burn, 1) : null,
        'cash_cover_months' => $monthlyOut > 0 ? round($cash / $monthlyOut, 1) : null,
        'ar' => $aging, 'dso_days' => $billed90 > 0 ? (int) round($aging['total'] / ($billed90 / 90)) : null,
        'billed_90d' => $billed90, 'collected_90d' => $collected90, 'collection_rate_pct' => ledger_pct($collected90, $billed90),
        'revenue_12m' => $rev12, 'write_offs_12m' => $writeOffs12, 'write_off_rate_pct' => ledger_pct($writeOffs12, $rev12),
        'top_client' => ledger_top_party($pdo, $bookId, $from12, $asOf),
    ];
}

/** Receivables issued (billed) in a period. */
function ledger_billed(PDO $pdo, int $bookId, string $from, string $to): int
{
    $q = $pdo->prepare("SELECT coalesce(SUM(total_cents), 0) FROM ledger.documents WHERE book_id = :b AND kind = 'receivable' AND status = 'open'
                          AND issued_on BETWEEN CAST(:f AS date) AND CAST(:t AS date)");
    $q->execute(['b' => $bookId, 'f' => $from, 't' => $to]);
    return (int) $q->fetchColumn();
}

/** Payments received on receivables in a period (not reversed). */
function ledger_collected(PDO $pdo, int $bookId, string $from, string $to): int
{
    $q = $pdo->prepare("SELECT coalesce(SUM(s.amount_cents), 0) FROM ledger.settlements s JOIN ledger.documents d ON d.id = s.document_id
                         WHERE s.book_id = :b AND d.kind = 'receivable' AND s.reversed_at IS NULL AND s.settled_on BETWEEN CAST(:f AS date) AND CAST(:t AS date)");
    $q->execute(['b' => $bookId, 'f' => $from, 't' => $to]);
    return (int) $q->fetchColumn();
}

/** The party billed most in a period, and its share of everything billed. */
function ledger_top_party(PDO $pdo, int $bookId, string $from, string $to): ?array
{
    $q = $pdo->prepare("SELECT party, SUM(total_cents) AS billed FROM ledger.documents WHERE book_id = :b AND kind = 'receivable' AND status = 'open'
                          AND issued_on BETWEEN CAST(:f AS date) AND CAST(:t AS date) GROUP BY party ORDER BY SUM(total_cents) DESC");
    $q->execute(['b' => $bookId, 'f' => $from, 't' => $to]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_map(fn($r) => (int) $r['billed'], $rows));
    if (!$rows || $total <= 0) {
        return null;
    }
    return ['party' => (string) $rows[0]['party'], 'billed' => (int) $rows[0]['billed'], 'share_pct' => ledger_pct((int) $rows[0]['billed'], $total), 'parties' => count($rows)];
}

/**
 * Findings: each a measured fact, the rule that judged it, how serious it is,
 * and what to do. Sorted most serious first.
 *
 * $hygiene, from the host: unassigned payments, days since the last bank
 * statement, days since the last signed close, tie-out problems.
 */
function ledger_findings(array $p, array $hygiene = []): array
{
    $f = [];
    $add = function (string $code, string $sev, string $title, array $measured, string $rule, string $advice) use (&$f) {
        $f[] = compact('code', 'title', 'measured', 'rule', 'advice') + ['severity' => $sev];
    };
    $money = fn(int $c) => ledger_money($c);
    if ($p['months_with_activity'] < 3) {
        $add('thin_data', 'note', 'Not enough history to judge trends', ['months_with_activity' => $p['months_with_activity']],
            'Trends and ratios need at least three whole months of activity.',
            'Keep recording everything; trend findings start once three whole months are in the books.');
    }
    // Books first: no analysis is better than the books under it.
    foreach ($hygiene['tie_out_problems'] ?? [] as $t) {
        $add('books_do_not_tie', 'critical', 'The books do not tie out', ['problem' => $t], 'Every statement must agree with every other.',
            'Fix this before relying on any figure below: ' . $t);
    }
    if (($hygiene['unassigned_payments'] ?? 0) > 0) {
        $add('payments_unassigned', 'warning', 'Payments waiting to be assigned', ['count' => $hygiene['unassigned_payments']],
            'On the cash basis, a part payment counts as revenue only once it is assigned to invoice lines.',
            'Assign them on the Books page; until then cash-basis revenue is understated.');
    }
    if (($hygiene['days_since_bank'] ?? null) === null || $hygiene['days_since_bank'] > 45) {
        $add('bank_not_reconciled', 'warning', 'The operating bank is not reconciled recently',
            ['days_since_last_statement' => $hygiene['days_since_bank'] ?? null], 'Reconcile the operating bank at least monthly.',
            'Import last month\'s bank statement so the cash figures below are confirmed by the bank.');
    }
    if (($hygiene['days_since_close'] ?? null) === null || $hygiene['days_since_close'] > 45) {
        $add('period_not_closed', 'note', 'No month signed off recently', ['days_since_last_close' => $hygiene['days_since_close'] ?? null],
            'Sign off each month once it is reconciled.', 'Close last month so its figures are locked and fingerprinted.');
    }
    // Liquidity.
    if ($p['runway_months'] !== null) {
        $sev = $p['runway_months'] < 3 ? 'critical' : ($p['runway_months'] < 6 ? 'warning' : 'note');
        $add('cash_burn', $sev, 'Spending more cash than comes in',
            ['monthly_cash_burn' => $p['monthly_cash_burn'], 'cash' => $p['cash'], 'runway_months' => $p['runway_months']],
            'Runway = spendable cash / average monthly cash burn (last three months). Under 3 months is critical, under 6 a warning.',
            sprintf('At the last three months\' pace, cash of %s lasts about %s months. Collect receivables first (%s open), then cut or defer costs.',
                $money($p['cash']), $p['runway_months'], $money($p['ar']['total'])));
    } elseif ($p['cash_cover_months'] !== null && $p['cash_cover_months'] < 1) {
        $add('thin_cash_buffer', 'warning', 'Less than a month of costs in the bank',
            ['cash' => $p['cash'], 'avg_monthly_cash_out' => $p['avg_monthly_cash_out'], 'months_covered' => $p['cash_cover_months']],
            'Hold at least one month of costs in cash.', sprintf('Build the buffer to %s before new commitments.', $money($p['avg_monthly_cash_out'])));
    } elseif ($p['cash_cover_months'] !== null && $p['months_with_activity'] >= 3) {
        $add('cash_buffer_ok', 'good', 'Cash covers your costs', ['cash' => $p['cash'], 'months_covered' => $p['cash_cover_months']],
            'At least one month of costs in cash, and not burning cash.', 'No action needed.');
    }
    // Receivables.
    if ($p['ar']['total'] > 0) {
        $old = $p['ar']['61_90'] + $p['ar']['over_90'];
        $share = ledger_pct($old, $p['ar']['total']);
        if ($share !== null && $share >= 20) {
            $add('old_receivables', $share >= 40 ? 'critical' : 'warning', 'Receivables are getting old',
                ['over_60_days' => $old, 'share_of_receivables_pct' => $share, 'receivables' => $p['ar']['total']],
                'More than 20% of what clients owe is over 60 days past due (40% is critical).',
                sprintf('Chase the %s that is over 60 days late; consider a payment plan, or ask the owner about a write-off for what will not come in.', $money($old)));
        }
    }
    if ($p['dso_days'] !== null && $p['dso_days'] > 60 && $p['months_with_activity'] >= 3) {
        $add('slow_collections', 'warning', 'Clients take a long time to pay', ['days_sales_outstanding' => $p['dso_days']],
            'Days sales outstanding = receivables / average daily billing (last 90 days). Above 60 days is slow for a professional practice.',
            'Bill more often, ask for retainers in trust, and offer online payment.');
    }
    if ($p['collection_rate_pct'] !== null && $p['collection_rate_pct'] < 80 && $p['months_with_activity'] >= 3) {
        $add('low_collection_rate', 'warning', 'Collecting less than you bill', ['collected_90d' => $p['collected_90d'], 'billed_90d' => $p['billed_90d'], 'collection_rate_pct' => $p['collection_rate_pct']],
            'Collected in the last 90 days / billed in the last 90 days, under 80%.', 'Follow up the oldest invoices first.');
    }
    // Profitability and trend.
    if ($p['months_with_activity'] >= 3 && $p['revenue_3m'] > 0) {
        if ($p['net_3m'] < 0) {
            $add('losing_money', 'critical', 'The last three months lost money',
                ['net_income_3m' => $p['net_3m'], 'revenue_3m' => $p['revenue_3m'], 'expenses_3m' => $p['expenses_3m']],
                'Net income over the last three whole months, accrual basis.',
                sprintf('Expenses ran %s above revenue. Review the largest expense accounts on the profit and loss before adding any cost.', $money(-$p['net_3m'])));
        } elseif ($p['net_margin_pct'] !== null && $p['net_margin_pct'] < 10) {
            $add('thin_margin', 'warning', 'Thin profit margin', ['net_margin_pct' => $p['net_margin_pct']],
                'Net margin over the last three months under 10%.', 'Review rates and the largest expenses before new commitments.');
        } elseif ($p['net_margin_pct'] !== null) {
            $add('healthy_margin', 'good', 'Profitable', ['net_margin_pct' => $p['net_margin_pct'], 'net_income_3m' => $p['net_3m']],
                'Net margin over the last three months of 10% or more.', 'No action needed.');
        }
        if ($p['revenue_growth_pct'] !== null && $p['expense_growth_pct'] !== null && $p['expense_growth_pct'] - $p['revenue_growth_pct'] > 10) {
            $add('costs_outpacing_revenue', 'warning', 'Costs are growing faster than revenue',
                ['expense_growth_pct' => $p['expense_growth_pct'], 'revenue_growth_pct' => $p['revenue_growth_pct']],
                'Last three months against the three before: expenses grew more than 10 points faster than revenue.',
                'Find which expense accounts grew (compare periods on the profit and loss) and decide whether each is temporary.');
        }
        if ($p['revenue_growth_pct'] !== null && $p['revenue_growth_pct'] <= -20) {
            $add('revenue_falling', 'warning', 'Revenue is falling', ['revenue_growth_pct' => $p['revenue_growth_pct']],
                'Last three months against the three before: down 20% or more.', 'Look at intake: new matters and prospects in the pipeline.');
        }
    }
    // Concentration and write-offs.
    if ($p['top_client'] !== null && $p['top_client']['parties'] >= 2 && $p['top_client']['share_pct'] >= 40) {
        $add('client_concentration', $p['top_client']['share_pct'] >= 60 ? 'critical' : 'warning', 'One client is most of the business',
            ['party' => $p['top_client']['party'], 'share_pct' => $p['top_client']['share_pct']],
            'One client billed 40% or more of everything over 12 months (60% is critical).',
            'Losing this client would hit hard: widen intake, and keep that client\'s retainer funded in trust.');
    }
    if ($p['write_off_rate_pct'] !== null && $p['write_off_rate_pct'] >= 5) {
        $add('high_write_offs', 'warning', 'Many fees written off', ['write_offs_12m' => $p['write_offs_12m'], 'write_off_rate_pct' => $p['write_off_rate_pct']],
            'Write-offs over 12 months, 5% of revenue or more.', 'Take retainers up front and bill monthly so less goes uncollected.');
    }
    usort($f, fn($a, $b) => LEDGER_SEVERITIES[$a['severity']] <=> LEDGER_SEVERITIES[$b['severity']]);
    return $f;
}

/* ── Feasibility: should the owner do this? ──────────────────────────── */

const LEDGER_SCENARIOS = ['recurring_cost', 'hire', 'one_time_purchase', 'loan', 'revenue_change'];

/**
 * Test a proposed move against the books and the forecast.
 *
 * $s: kind (one of LEDGER_SCENARIOS), and
 *   recurring_cost:    monthly (cents), starts_on, name
 *   hire:              annual_salary (cents), burden_pct (default 20: payroll taxes and benefits), starts_on
 *   one_time_purchase: amount (cents), on, name
 *   loan:              principal (cents), monthly_payment (cents), on
 *   revenue_change:    pct (e.g. 10 or -15), starts_on
 *
 * Returns the verdict ('recommended' | 'caution' | 'not_recommended'), the
 * reasons, and every figure behind it, before and after.
 */
function ledger_feasibility(PDO $pdo, int $bookId, string $asOf, array $s, array $recurring = []): array
{
    $kind = (string) ($s['kind'] ?? '');
    if (!in_array($kind, LEDGER_SCENARIOS, true)) {
        throw new LedgerError('Unknown scenario.');
    }
    $p = ledger_financial_profile($pdo, $bookId, $asOf);
    $start = ledger_is_date((string) ($s['starts_on'] ?? $s['on'] ?? '')) ? (string) ($s['starts_on'] ?? $s['on']) : $asOf;
    $start = max($start, $asOf);   // something already past is in the books, not a decision to test
    $extra = [];
    $monthlyDelta = 0;     // change to monthly net income (cents)
    $label = '';
    switch ($kind) {
        case 'recurring_cost':
            $m = (int) ($s['monthly'] ?? 0);
            if ($m <= 0) {
                throw new LedgerError('A recurring cost needs a monthly amount.');
            }
            $label = ($s['name'] ?? 'New recurring cost') . ' at ' . ledger_money($m) . ' a month';
            $extra[] = ['name' => $label, 'amount' => $m, 'day' => (int) substr($start, 8, 2) ?: 1, 'from' => $start];
            $monthlyDelta = -$m;
            break;
        case 'hire':
            $salary = (int) ($s['annual_salary'] ?? 0);
            $burden = (float) ($s['burden_pct'] ?? 20);
            if ($salary <= 0 || $burden < 0 || $burden > 100) {
                throw new LedgerError('A hire needs an annual salary (and a burden of 0 to 100%).');
            }
            $m = (int) round($salary * (1 + $burden / 100) / 12);
            $label = 'A hire at ' . ledger_money($salary) . ' a year (' . ledger_money($m) . ' a month with ' . rtrim(rtrim(number_format($burden, 1), '0'), '.') . '% for taxes and benefits)';
            // Paid twice a month: half on the 15th, half at month end.
            $extra[] = ['name' => 'Payroll (15th)', 'amount' => intdiv($m, 2), 'day' => 15, 'from' => $start];
            $extra[] = ['name' => 'Payroll (month end)', 'amount' => $m - intdiv($m, 2), 'day' => 31, 'from' => $start];
            $monthlyDelta = -$m;
            break;
        case 'one_time_purchase':
            $a = (int) ($s['amount'] ?? 0);
            if ($a <= 0) {
                throw new LedgerError('A purchase needs an amount.');
            }
            $label = ($s['name'] ?? 'A purchase') . ' of ' . ledger_money($a);
            $extra[] = ['name' => $label, 'amount' => $a, 'once_on' => $start];
            break;
        case 'loan':
            $pr = (int) ($s['principal'] ?? 0);
            $pay = (int) ($s['monthly_payment'] ?? 0);
            if ($pr <= 0 || $pay <= 0) {
                throw new LedgerError('A loan needs the amount borrowed and the monthly payment.');
            }
            $label = 'Borrowing ' . ledger_money($pr) . ' at ' . ledger_money($pay) . ' a month';
            $extra[] = ['name' => 'Loan received', 'amount' => $pr, 'once_on' => $start, 'direction' => 'in'];
            $first = gmdate('Y-m-d', strtotime($start . ' +1 month'));
            $extra[] = ['name' => 'Loan payment', 'amount' => $pay, 'day' => (int) substr($first, 8, 2), 'from' => $first];
            $monthlyDelta = -$pay;   // treated as cash out; the interest part is the expense
            break;
        case 'revenue_change':
            $pct = (float) ($s['pct'] ?? 0);
            if ($pct == 0.0 || $pct < -100 || $pct > 500) {
                throw new LedgerError('A revenue change needs a percentage, like 10 or -15.');
            }
            $m = (int) round($p['avg_monthly_revenue'] * $pct / 100);
            $label = 'Revenue ' . ($pct > 0 ? 'up' : 'down') . ' ' . rtrim(rtrim(number_format(abs($pct), 1), '0'), '.') . '%';
            $extra[] = ['name' => $label, 'amount' => abs($m), 'day' => 28, 'from' => $start, 'direction' => $m >= 0 ? 'in' : 'out'];
            $monthlyDelta = $m;
            break;
    }
    // The forecast knows only what is already billed. A practice that keeps working keeps
    // billing, so with three months of history its usual collections (cash-basis revenue,
    // averaged) are expected on the 28th of each month from a month out, once today's
    // receivables have had time to come in, with a 30% band. Recurring costs alone,
    // without the revenue that pays them, would make every decision look unaffordable.
    $runRate = 0;
    if ($p['months_with_activity'] >= 3) {
        $cashMonths = ledger_monthly($pdo, $bookId, 'cash', $asOf, 3);
        $runRate = max(0, (int) round(ledger_avg(array_column($cashMonths, 'revenue'))));
        if ($runRate > 0) {
            $recurring[] = ['name' => 'Usual monthly collections (last three months)', 'amount' => $runRate, 'day' => 28,
                            'from' => gmdate('Y-m-d', strtotime("$asOf +30 days")), 'direction' => 'in', 'band' => 0.3];
        }
    }
    $before = ledger_cash_forecast($pdo, $bookId, $asOf, $recurring);
    $after = ledger_cash_forecast($pdo, $bookId, $asOf, array_merge($recurring, $extra));
    $low = fn(array $fc) => $fc['lowest'] === null ? null : (int) $fc['lowest']['amount'];
    $lowBefore = $low($before);
    $lowAfter = $low($after);
    $buffer = $p['avg_monthly_cash_out'];   // one month of today's costs
    $netAfter = $p['avg_monthly_net'] + $monthlyDelta;
    $revAfter = $p['avg_monthly_revenue'] + ($kind === 'revenue_change' ? $monthlyDelta : 0);
    $marginAfter = ledger_pct($netAfter, $revAfter);
    $cashNetAfter = $p['avg_monthly_cash_net'] + $monthlyDelta;
    $burnAfter = $cashNetAfter < 0 ? -$cashNetAfter : 0;
    $cashAfterStart = $p['cash'] + ($kind === 'loan' ? (int) $s['principal'] : 0) - ($kind === 'one_time_purchase' ? (int) $s['amount'] : 0);
    $runwayAfter = $burnAfter > 0 ? round($cashAfterStart / $burnAfter, 1) : null;

    $reasons = [];
    $verdict = 'recommended';
    if ($p['months_with_activity'] < 3) {
        $verdict = 'caution';
        $reasons[] = 'There are fewer than three whole months of activity in the books, so the trend behind this answer is thin.';
    }
    if ($lowAfter !== null && $lowAfter < 0) {
        $verdict = 'not_recommended';
        $reasons[] = sprintf('On the low estimate, cash would fall to %s within 90 days.', ledger_money($lowAfter));
    } elseif ($lowAfter !== null && $lowAfter < $buffer) {
        $verdict = $verdict === 'not_recommended' ? $verdict : 'caution';
        $reasons[] = sprintf('On the low estimate, cash would fall to %s within 90 days, under one month of costs (%s).', ledger_money($lowAfter), ledger_money($buffer));
    }
    if ($monthlyDelta < 0 && $netAfter < 0 && $p['months_with_activity'] >= 3) {
        $verdict = 'not_recommended';
        $reasons[] = sprintf('Average monthly net income would turn negative: %s a month (now %s).', ledger_money($netAfter), ledger_money($p['avg_monthly_net']));
    } elseif ($marginAfter !== null && $marginAfter < 10 && $monthlyDelta < 0 && $p['months_with_activity'] >= 3) {
        $verdict = $verdict === 'not_recommended' ? $verdict : 'caution';
        $reasons[] = sprintf('Net margin would drop to %s%% (now %s%%).', $marginAfter, $p['net_margin_pct'] ?? 0);
    }
    if ($runwayAfter !== null && $runwayAfter < 6) {
        $verdict = $runwayAfter < 3 ? 'not_recommended' : ($verdict === 'not_recommended' ? $verdict : 'caution');
        $reasons[] = sprintf('Cash would last about %s months at the new pace.', $runwayAfter);
    }
    if ($lowAfter === null) {
        $verdict = $verdict === 'not_recommended' ? $verdict : 'caution';
        $reasons[] = 'The books have no cash balance yet, so the effect on cash cannot be measured.';
    }
    if (!$reasons) {
        $reasons[] = sprintf('Cash stays above one month of costs on the low estimate (lowest point %s), and the business stays profitable (%s a month after the change).',
            ledger_money((int) $lowAfter), ledger_money($netAfter));
    }
    if ($runRate > 0) {
        $reasons[] = sprintf('Assumes usual collections of %s a month from a month out (the average of the last three months, cash basis): an assumption, not money already billed.', ledger_money($runRate));
    }
    return [
        'scenario' => $label, 'kind' => $kind, 'verdict' => $verdict, 'reasons' => $reasons,
        'figures' => [
            'cash_now' => $p['cash'], 'lowest_cash_90d_before' => $lowBefore, 'lowest_cash_90d_after' => $lowAfter, 'lowest_cash_on' => $after['lowest']['on'] ?? null,
            'one_month_of_costs' => $buffer,
            'avg_monthly_net_before' => $p['avg_monthly_net'], 'avg_monthly_net_after' => $netAfter,
            'net_margin_pct_before' => $p['net_margin_pct'], 'net_margin_pct_after' => $marginAfter,
            'runway_months_before' => $p['runway_months'], 'runway_months_after' => $runwayAfter,
            'monthly_change' => $monthlyDelta, 'expected_monthly_collections' => $runRate,
        ],
        'forecast_after' => $after['windows'],
    ];
}
