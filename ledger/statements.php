<?php
declare(strict_types=1);

/**
 * Financial statements, built from posted journal lines only: profit and
 * loss, balance sheet, cash flow (indirect method) and owner's equity, on
 * either basis, for any period, with comparison columns and the tie-outs
 * that prove the four agree. Nothing here is stored; every figure can be
 * traced to the lines behind it.
 *
 * Signs. Statements show amounts the way a reader expects: revenue, income,
 * liabilities and equity positive when they are in their normal balance,
 * expenses and assets positive likewise. Underneath, balances are signed
 * debit-positive as everywhere else in the core.
 */

require_once __DIR__ . '/ledger.php';

/**
 * Where each statement line sits. Accounts carry a statement_line; this
 * puts it in its section and order. Unknown lines fall into a sensible
 * default for their type, so a pack can add lines without touching this.
 */
const LEDGER_BS_LINES = [
    // assets: [section, order]
    'Cash and cash equivalents'        => ['current_assets', 10],
    'Restricted cash: client trust'    => ['current_assets', 15],
    'Accounts receivable'              => ['current_assets', 20],
    'Prepaid and other current assets' => ['current_assets', 40],
    'Property and equipment, net'      => ['noncurrent_assets', 10],
    'Intangible assets, net'           => ['noncurrent_assets', 20],
    'Other non-current assets'         => ['noncurrent_assets', 90],
    // liabilities
    'Accounts payable'                 => ['current_liabilities', 10],
    'Accrued liabilities'              => ['current_liabilities', 20],
    'Client trust funds'               => ['current_liabilities', 25],
    'Deferred revenue'                 => ['current_liabilities', 30],
    'Other current liabilities'        => ['current_liabilities', 90],
    'Long-term debt'                   => ['noncurrent_liabilities', 10],
    'Other non-current liabilities'    => ['noncurrent_liabilities', 90],
];

const LEDGER_PL_SECTIONS = [
    'revenue'       => 'Revenue',
    'cogs'          => 'Cost of revenue',
    'opex'          => 'Operating expenses',
    'other_income'  => 'Other income',
    'other_expense' => 'Other expense',
    'tax'           => 'Income tax expense',
];

/** Which P&L section an account belongs to. */
function ledger_pl_section(array $a): string
{
    if ($a['type'] === 'revenue') {
        return $a['statement_line'] === 'Other income' ? 'other_income' : 'revenue';
    }
    return match ($a['statement_line']) {
        'Cost of revenue'          => 'cogs',
        'Income tax expense'       => 'tax',
        'Other income and expense' => 'other_expense',
        default                    => 'opex',
    };
}

/**
 * Activity per account on a basis between two dates (inclusive; null from
 * means since the beginning). Each row: the account and its debit-positive
 * net movement. Accounts with no lines are left out.
 */
function ledger_activity(PDO $pdo, int $bookId, string $basis, ?string $from, string $to, bool $excludeClosing = false): array
{
    if (!in_array($basis, ['accrual', 'cash'], true)) {
        throw new LedgerError('A basis is accrual or cash.');
    }
    // A statement page asks for the same activity many times: cache it for
    // this request, keyed on the newest entry so any posting starts afresh.
    $v = $pdo->prepare('SELECT coalesce(MAX(id), 0) FROM ledger.entries WHERE book_id = :b');
    $v->execute(['b' => $bookId]);
    $ck = implode('|', [spl_object_id($pdo), $bookId, $basis, $from ?? '', $to, $excludeClosing ? 1 : 0, (int) $v->fetchColumn(), $GLOBALS['ledger_cache_epoch'] ?? 0]);
    static $cache = [];
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    if (count($cache) > 200) {
        $cache = [];
    }
    return $cache[$ck] = ledger_activity_query($pdo, $bookId, $basis, $from, $to, $excludeClosing);
}

/** Forget cached activity (an account was renamed or re-tagged). */
function ledger_cache_clear(): void
{
    $GLOBALS['ledger_cache_epoch'] = ($GLOBALS['ledger_cache_epoch'] ?? 0) + 1;
}

function ledger_activity_query(PDO $pdo, int $bookId, string $basis, ?string $from, string $to, bool $excludeClosing): array
{
    [$src, $p] = ledger_sum_source($bookId, $basis, $from, $to, ['exclude_closing' => $excludeClosing]);
    $q = $pdo->prepare("SELECT a.id, a.code, a.name, a.type, a.contra, a.statement_line, a.cash_flow_class, a.equity_type,
                               a.owner_party, a.control, a.system_key, a.active,
                               SUM(s.debit_cents - s.credit_cents) AS net
                          FROM $src s JOIN ledger.accounts a ON a.id = s.account_id
                         GROUP BY a.id ORDER BY a.code");
    $q->execute($p);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['net'] = (int) $r['net'];
        $r['contra'] = (bool) $r['contra'];
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

function ledger_day_before(string $d): string
{
    return gmdate('Y-m-d', strtotime($d . ' -1 day'));
}

/** The first day of the fiscal year that contains $d. */
function ledger_fiscal_year_start(PDO $pdo, int $bookId, string $d): string
{
    $q = $pdo->prepare('SELECT fiscal_year_start FROM ledger.books WHERE id = :b');
    $q->execute(['b' => $bookId]);
    $m = (int) ($q->fetchColumn() ?: 1);
    $y = (int) substr($d, 0, 4);
    if ((int) substr($d, 5, 2) < $m) {
        $y--;
    }
    return sprintf('%04d-%02d-01', $y, $m);
}

/* ── Profit and loss ─────────────────────────────────────────────────── */

/**
 * @return array{basis:string, from:string, to:string, sections:array, gross_profit:int,
 *               operating_income:int, income_before_tax:int, net_income:int}
 */
function ledger_profit_and_loss(PDO $pdo, int $bookId, string $basis, string $from, string $to): array
{
    $sections = [];
    foreach (LEDGER_PL_SECTIONS as $k => $label) {
        $sections[$k] = ['key' => $k, 'label' => $label, 'rows' => [], 'total' => 0];
    }
    foreach (ledger_activity($pdo, $bookId, $basis, $from, $to, true) as $a) {
        if (!in_array($a['type'], ['revenue', 'expense'], true) || $a['net'] === 0) {
            continue;
        }
        $s = ledger_pl_section($a);
        // Income sections read credit-positive, cost sections debit-positive.
        $amount = in_array($s, ['revenue', 'other_income'], true) ? -$a['net'] : $a['net'];
        $sections[$s]['rows'][] = ['account_id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'amount' => $amount];
        $sections[$s]['total'] += $amount;
    }
    $t = fn($k) => $sections[$k]['total'];
    $gross = $t('revenue') - $t('cogs');
    $operating = $gross - $t('opex');
    $beforeTax = $operating + $t('other_income') - $t('other_expense');
    return [
        'basis' => $basis, 'from' => $from, 'to' => $to, 'sections' => $sections,
        'gross_profit' => $gross, 'operating_income' => $operating,
        'income_before_tax' => $beforeTax, 'net_income' => $beforeTax - $t('tax'),
    ];
}

/** Net income for a period on a basis: credits less debits on revenue and expense accounts. */
function ledger_net_income(PDO $pdo, int $bookId, string $basis, ?string $from, string $to, bool $excludeClosing = false): int
{
    $n = 0;
    foreach (ledger_activity($pdo, $bookId, $basis, $from, $to, $excludeClosing) as $a) {
        if (in_array($a['type'], ['revenue', 'expense'], true)) {
            $n -= $a['net'];
        }
    }
    return $n;
}

/* ── Balance sheet ───────────────────────────────────────────────────── */

/**
 * As of a date. Equity shows each equity account plus two computed lines:
 * earnings of earlier fiscal years not yet closed into an account, and
 * current-year earnings (fiscal year start through the date).
 */
function ledger_balance_sheet(PDO $pdo, int $bookId, string $basis, string $asOf): array
{
    $sec = [];
    foreach (['current_assets' => 'Current assets', 'noncurrent_assets' => 'Non-current assets',
              'current_liabilities' => 'Current liabilities', 'noncurrent_liabilities' => 'Non-current liabilities',
              'equity' => 'Equity'] as $k => $label) {
        $sec[$k] = ['key' => $k, 'label' => $label, 'rows' => [], 'total' => 0];
    }
    $order = [];
    foreach (ledger_activity($pdo, $bookId, $basis, null, $asOf) as $a) {
        if (in_array($a['type'], ['revenue', 'expense'], true) || $a['net'] === 0) {
            continue;
        }
        if ($a['type'] === 'equity') {
            $k = 'equity';
            $ord = 50;
        } else {
            [$k, $ord] = LEDGER_BS_LINES[$a['statement_line']]
                ?? [$a['type'] === 'asset' ? 'current_assets' : 'current_liabilities', 80];
        }
        $amount = $a['type'] === 'asset' ? $a['net'] : -$a['net'];
        $sec[$k]['rows'][] = ['account_id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'line' => $a['statement_line'],
                              'amount' => $amount, 'order' => $ord];
        $sec[$k]['total'] += $amount;
    }
    $fy = ledger_fiscal_year_start($pdo, $bookId, $asOf);
    $prior = ledger_net_income($pdo, $bookId, $basis, null, ledger_day_before($fy));
    $current = ledger_net_income($pdo, $bookId, $basis, $fy, $asOf);
    if ($prior !== 0) {
        $sec['equity']['rows'][] = ['account_id' => null, 'code' => '', 'name' => 'Earnings of earlier years (not yet closed)', 'line' => 'Retained earnings', 'amount' => $prior, 'order' => 60];
        $sec['equity']['total'] += $prior;
    }
    $sec['equity']['rows'][] = ['account_id' => null, 'code' => '', 'name' => 'Current-year earnings', 'line' => 'Current-year earnings', 'amount' => $current, 'order' => 70];
    $sec['equity']['total'] += $current;
    foreach ($sec as &$s) {
        usort($s['rows'], fn($x, $y) => [$x['order'], $x['code']] <=> [$y['order'], $y['code']]);
    }
    unset($s);
    $assets = $sec['current_assets']['total'] + $sec['noncurrent_assets']['total'];
    $liab = $sec['current_liabilities']['total'] + $sec['noncurrent_liabilities']['total'];
    return [
        'basis' => $basis, 'as_of' => $asOf, 'fiscal_year_start' => $fy, 'sections' => $sec,
        'total_assets' => $assets, 'total_liabilities' => $liab, 'total_equity' => $sec['equity']['total'],
        'current_year_earnings' => $current, 'balanced' => $assets === $liab + $sec['equity']['total'],
    ];
}

/* ── Cash flow (indirect) ───────────────────────────────────────────── */

/**
 * Net income, adjusted for every balance-sheet account other than cash, by
 * its cash flow class. Cash includes restricted cash (client trust money is
 * shown, and reconciled, but stays separate in the totals).
 */
function ledger_cash_flow(PDO $pdo, int $bookId, string $basis, string $from, string $to): array
{
    $out = [
        'basis' => $basis, 'from' => $from, 'to' => $to,
        'net_income' => 0,
        'operating' => ['rows' => [], 'total' => 0],
        'investing' => ['rows' => [], 'total' => 0],
        'financing' => ['rows' => [], 'total' => 0],
    ];
    $cashChange = $restrictedChange = 0;
    // Year-end closing entries move earnings between accounts; no cash moves.
    foreach (ledger_activity($pdo, $bookId, $basis, $from, $to, true) as $a) {
        if ($a['net'] === 0) {
            continue;
        }
        if (in_array($a['type'], ['revenue', 'expense'], true)) {
            $out['net_income'] -= $a['net'];
            continue;
        }
        if ($a['cash_flow_class'] === 'cash') {
            $cashChange += $a['net'];
            continue;
        }
        if ($a['cash_flow_class'] === 'restricted_cash') {
            $restrictedChange += $a['net'];
            continue;
        }
        // An account's balance going up (debit) used cash; going down freed it.
        $effect = -$a['net'];
        $class = match ($a['cash_flow_class']) {
            'investing' => 'investing',
            'financing' => 'financing',
            'none'      => $a['type'] === 'equity' ? 'financing' : 'operating',
            default     => 'operating',   // operating, and non-cash items added back (depreciation)
        };
        $label = $a['cash_flow_class'] === 'non_cash' ? $a['name'] . ' (non-cash)' : 'Change in ' . $a['name'];
        $out[$class]['rows'][] = ['account_id' => (int) $a['id'], 'code' => $a['code'], 'name' => $label, 'amount' => $effect];
        $out[$class]['total'] += $effect;
    }
    $out['operating']['total'] += $out['net_income'];
    $open = ledger_cash_balances($pdo, $bookId, $basis, ledger_day_before($from));
    $close = ledger_cash_balances($pdo, $bookId, $basis, $to);
    $out['net_change'] = $out['operating']['total'] + $out['investing']['total'] + $out['financing']['total'];
    $out['opening'] = $open;
    $out['closing'] = $close;
    $out['cash_change'] = $cashChange;
    $out['restricted_change'] = $restrictedChange;
    $out['ties'] = $out['net_change'] === $cashChange + $restrictedChange
        && $close['total'] - $open['total'] === $cashChange + $restrictedChange;
    return $out;
}

/** Cash and restricted cash at the end of a day. */
function ledger_cash_balances(PDO $pdo, int $bookId, string $basis, string $asOf): array
{
    $cash = $restricted = 0;
    foreach (ledger_activity($pdo, $bookId, $basis, null, $asOf) as $a) {
        if ($a['cash_flow_class'] === 'cash') {
            $cash += $a['net'];
        } elseif ($a['cash_flow_class'] === 'restricted_cash') {
            $restricted += $a['net'];
        }
    }
    return ['cash' => $cash, 'restricted' => $restricted, 'total' => $cash + $restricted];
}

/* ── Owner's equity ─────────────────────────────────────────────────── */

/**
 * Opening balance, movements by kind and closing balance for each equity
 * account (one per partner where the account names its owner), plus the
 * period's net income and earlier earnings not yet closed. The closing
 * total equals equity on the balance sheet at the period end.
 */
function ledger_equity_statement(PDO $pdo, int $bookId, string $basis, string $from, string $to): array
{
    $openActs = ledger_activity($pdo, $bookId, $basis, null, ledger_day_before($from));
    $moves = ledger_activity($pdo, $bookId, $basis, $from, $to);
    // What a year-end close moved into each equity account in the period.
    $q = $pdo->prepare("SELECT l.account_id, SUM(l.credit_cents - l.debit_cents) FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                         WHERE l.book_id = :b AND e.event_type = 'period.year_end' AND e.basis IN ('both', :basis)
                           AND e.entry_on BETWEEN CAST(:f AS date) AND CAST(:t AS date) GROUP BY l.account_id");
    $q->execute(['b' => $bookId, 'basis' => $basis, 'f' => $from, 't' => $to]);
    $allocated = array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    $rows = [];
    foreach ($openActs + $moves as $id => $a) {
        if ($a['type'] !== 'equity') {
            continue;
        }
        $open = -($openActs[$id]['net'] ?? 0);
        $alloc = $allocated[$id] ?? 0;
        $move = -($moves[$id]['net'] ?? 0) - $alloc;
        $kind = match ($a['equity_type']) {
            'contributions', 'capital', 'stock', 'paid_in_capital' => 'contributions',
            'draws', 'distributions'                               => 'draws',
            default                                                => 'other',
        };
        $rows[] = ['account_id' => (int) $id, 'code' => $a['code'], 'name' => $a['name'], 'owner' => $a['owner_party'],
                   'opening' => $open, 'contributions' => $kind === 'contributions' ? $move : 0,
                   'draws' => $kind === 'draws' ? $move : 0, 'other' => $kind === 'other' ? $move : 0,
                   'net_income' => 0, 'allocated' => $alloc, 'closing' => $open + $move + $alloc];
    }
    usort($rows, fn($x, $y) => strcmp($x['code'], $y['code']));
    // Earnings: everything before this period's start that is not yet in an
    // equity account, then this period's net income.
    $fyFrom = ledger_fiscal_year_start($pdo, $bookId, $from);
    $earlierOpen = ledger_net_income($pdo, $bookId, $basis, null, ledger_day_before($from));
    $ni = ledger_net_income($pdo, $bookId, $basis, $from, $to, true);
    $closed = $ni - ledger_net_income($pdo, $bookId, $basis, $from, $to);   // moved into an equity account by a year-end close
    $rows[] = ['account_id' => null, 'code' => '', 'name' => 'Earnings not yet closed', 'owner' => '',
               'opening' => $earlierOpen, 'contributions' => 0, 'draws' => 0, 'other' => 0, 'net_income' => $ni, 'allocated' => -$closed, 'closing' => $earlierOpen + $ni - $closed];
    $tot = ['opening' => 0, 'contributions' => 0, 'draws' => 0, 'other' => 0, 'net_income' => 0, 'allocated' => 0, 'closing' => 0];
    foreach ($rows as $r) {
        foreach ($tot as $k => $_) {
            $tot[$k] += $r[$k];
        }
    }
    return ['basis' => $basis, 'from' => $from, 'to' => $to, 'fiscal_year_start' => $fyFrom, 'rows' => $rows, 'totals' => $tot];
}

/* ── Comparisons ─────────────────────────────────────────────────────── */

/** The same span immediately before, and the same dates a year earlier. */
function ledger_comparison_periods(string $from, string $to): array
{
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo = ledger_day_before($from);
    $prevFrom = gmdate('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    // A whole month compares with the whole month before, whatever its length.
    if (substr($from, 8) === '01' && $to === gmdate('Y-m-t', strtotime($from)) && substr($from, 0, 7) === substr($to, 0, 7)) {
        $prevFrom = gmdate('Y-m-01', strtotime($from . ' -1 month'));
        $prevTo = gmdate('Y-m-t', strtotime($prevFrom));
    }
    $yr = function (string $d): string {
        $y = sprintf('%04d', (int) substr($d, 0, 4) - 1);
        $md = substr($d, 4);
        return ledger_is_date($y . $md) ? $y . $md : $y . '-02-28';   // 29 February in a year that has none
    };
    return ['prior_period' => [$prevFrom, $prevTo], 'prior_year' => [$yr($from), $yr($to)]];
}

/**
 * A P&L with prior-period and prior-year columns: each row and subtotal
 * carries the comparison amounts, the dollar change and the percentage
 * change (null when the comparison is zero).
 */
function ledger_profit_and_loss_compared(PDO $pdo, int $bookId, string $basis, string $from, string $to): array
{
    $cmp = ledger_comparison_periods($from, $to);
    $cur = ledger_profit_and_loss($pdo, $bookId, $basis, $from, $to);
    $pp = ledger_profit_and_loss($pdo, $bookId, $basis, ...$cmp['prior_period']);
    $py = ledger_profit_and_loss($pdo, $bookId, $basis, ...$cmp['prior_year']);
    $find = function (array $pl, string $sec, int $acct): int {
        foreach ($pl['sections'][$sec]['rows'] as $r) {
            if ($r['account_id'] === $acct) {
                return $r['amount'];
            }
        }
        return 0;
    };
    $var = fn(int $a, int $b) => ['change' => $a - $b, 'pct' => $b === 0 ? null : round(($a - $b) / abs($b) * 100, 1)];
    foreach ($cur['sections'] as $k => &$s) {
        // Accounts present only in a comparison period still get a row.
        $seen = array_column($s['rows'], 'account_id');
        foreach ([$pp, $py] as $other) {
            foreach ($other['sections'][$k]['rows'] as $r) {
                if (!in_array($r['account_id'], $seen, true)) {
                    $s['rows'][] = ['account_id' => $r['account_id'], 'code' => $r['code'], 'name' => $r['name'], 'amount' => 0];
                    $seen[] = $r['account_id'];
                }
            }
        }
        usort($s['rows'], fn($x, $y) => strcmp($x['code'], $y['code']));
        foreach ($s['rows'] as &$r) {
            $r['prior_period'] = $find($pp, $k, $r['account_id']);
            $r['prior_year'] = $find($py, $k, $r['account_id']);
            $r['vs_prior_period'] = $var($r['amount'], $r['prior_period']);
            $r['vs_prior_year'] = $var($r['amount'], $r['prior_year']);
        }
        unset($r);
        $s['prior_period'] = $pp['sections'][$k]['total'];
        $s['prior_year'] = $py['sections'][$k]['total'];
    }
    unset($s);
    foreach (['gross_profit', 'operating_income', 'income_before_tax', 'net_income'] as $k) {
        $cur[$k . '_compared'] = ['prior_period' => $pp[$k], 'prior_year' => $py[$k],
                                   'vs_prior_period' => $var($cur[$k], $pp[$k]), 'vs_prior_year' => $var($cur[$k], $py[$k])];
    }
    $cur['periods'] = $cmp;
    // Margins, as a share of revenue (null with no revenue).
    $rev = $cur['sections']['revenue']['total'];
    $cur['margins'] = $rev === 0 ? null : [
        'gross' => round($cur['gross_profit'] / $rev * 100, 1),
        'operating' => round($cur['operating_income'] / $rev * 100, 1),
        'net' => round($cur['net_income'] / $rev * 100, 1),
    ];
    return $cur;
}

/* ── Tie-outs ────────────────────────────────────────────────────────── */

/**
 * Every check that proves the statements agree, for a period on a basis.
 * Returns a list of problems; empty means the set may issue.
 */
function ledger_tie_outs(PDO $pdo, int $bookId, string $basis, string $from, string $to): array
{
    $p = [];
    $tb = ledger_trial_balance($pdo, $bookId, $basis, $to);
    if (!$tb['balanced']) {
        $p[] = sprintf('The trial balance does not balance: debits %d, credits %d.', $tb['debits'], $tb['credits']);
    }
    $bs = ledger_balance_sheet($pdo, $bookId, $basis, $to);
    if (!$bs['balanced']) {
        $p[] = sprintf('Assets (%d) do not equal liabilities (%d) plus equity (%d).', $bs['total_assets'], $bs['total_liabilities'], $bs['total_equity']);
    }
    $cf = ledger_cash_flow($pdo, $bookId, $basis, $from, $to);
    $bsCash = ledger_cash_balances($pdo, $bookId, $basis, $to)['total'];
    if (!$cf['ties'] || $cf['closing']['total'] !== $bsCash) {
        $p[] = 'The cash flow statement does not reconcile to cash on the balance sheet.';
    }
    $eq = ledger_equity_statement($pdo, $bookId, $basis, $from, $to);
    if ($eq['totals']['closing'] !== $bs['total_equity']) {
        $p[] = sprintf('The equity statement closes at %d; the balance sheet shows equity of %d.', $eq['totals']['closing'], $bs['total_equity']);
    }
    $pl = ledger_profit_and_loss($pdo, $bookId, $basis, $from, $to);
    if ($pl['net_income'] !== $cf['net_income'] || $pl['net_income'] !== $eq['totals']['net_income']) {
        $p[] = 'Net income differs between the profit and loss, the cash flow and the equity statements.';
    }
    // Control accounts against what the documents say is owed (accrual only;
    // receivables and payables are not on the cash view).
    if ($basis === 'accrual') {
        foreach (['receivable' => 1, 'payable' => -1] as $kind => $sign) {
            $q = $pdo->prepare("SELECT a.id, a.name, coalesce((SELECT SUM(l.debit_cents - l.credit_cents) FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id
                                                           WHERE l.account_id = a.id AND e.basis IN ('both','accrual') AND e.entry_on <= CAST(:t AS date)), 0) AS bal
                                  FROM ledger.accounts a WHERE a.book_id = :b AND a.control = :k");
            $q->execute(['b' => $bookId, 'k' => $kind, 't' => $to]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $acct) {
                $owed = ledger_documents_owed($pdo, $bookId, (int) $acct['id'], $to);
                if ($sign * (int) $acct['bal'] !== $owed) {
                    $p[] = sprintf('%s is %d in the journal; open %ss add up to %d.', $acct['name'], $sign * (int) $acct['bal'], $kind, $owed);
                }
            }
        }
    }
    foreach (ledger_control_problems($pdo, $bookId, $basis) as $c) {
        $p[] = $c;
    }
    return $p;
}

/**
 * What documents posted to a control account still owe at the end of a day:
 * totals of documents issued (and not voided) by then, less payments and
 * write-offs dated by then and not reversed by then.
 */
function ledger_documents_owed(PDO $pdo, int $bookId, int $controlId, string $asOf): int
{
    $q = $pdo->prepare("SELECT coalesce(SUM(d.total_cents
                              - coalesce((SELECT SUM(s.amount_cents) FROM ledger.settlements s
                                           JOIN ledger.entries ae ON ae.id = s.accrual_entry_id
                                          WHERE s.document_id = d.id AND ae.entry_on <= CAST(:t1 AS date)
                                            AND NOT EXISTS (SELECT 1 FROM ledger.entries r WHERE r.reverses_id = s.accrual_entry_id AND r.entry_on <= CAST(:t2 AS date))), 0)
                              - coalesce((SELECT SUM(w.amount_cents) FROM ledger.write_offs w JOIN ledger.entries we ON we.id = w.entry_id
                                          WHERE w.document_id = d.id AND we.entry_on <= CAST(:t3 AS date)), 0)), 0)
                          FROM ledger.documents d
                          JOIN ledger.entries ie ON ie.id = d.issue_entry_id AND ie.entry_on <= CAST(:t4 AS date)
                         WHERE d.book_id = :b AND d.control_account_id = :c
                           AND NOT (d.void_entry_id IS NOT NULL AND EXISTS (SELECT 1 FROM ledger.entries ve WHERE ve.id = d.void_entry_id AND ve.entry_on <= CAST(:t5 AS date)))");
    $q->execute(['b' => $bookId, 'c' => $controlId, 't1' => $asOf, 't2' => $asOf, 't3' => $asOf, 't4' => $asOf, 't5' => $asOf]);
    return (int) $q->fetchColumn();
}

/**
 * The statement set for a period, as one structure, and its SHA-256. The
 * fingerprint covers every figure, so regenerating the set from the same
 * journal gives the same hash, and any change to what was posted does not.
 */
function ledger_statement_set(PDO $pdo, int $bookId, string $basis, string $from, string $to): array
{
    $set = [
        'basis' => $basis, 'from' => $from, 'to' => $to,
        'profit_and_loss' => ledger_profit_and_loss($pdo, $bookId, $basis, $from, $to),
        'balance_sheet' => ledger_balance_sheet($pdo, $bookId, $basis, $to),
        'cash_flow' => ledger_cash_flow($pdo, $bookId, $basis, $from, $to),
        'equity' => ledger_equity_statement($pdo, $bookId, $basis, $from, $to),
        'trial_balance' => ledger_trial_balance($pdo, $bookId, $basis, $to),
    ];
    // The fingerprint covers figures and which account they belong to, not
    // names or codes: renaming an account must not break a signed close.
    $set['sha256'] = hash('sha256', json_encode(ledger_figures_only($set), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $set;
}

/** A statement set with every presentation field (names, codes, labels) removed. */
function ledger_figures_only(array $a): array
{
    $out = [];
    foreach ($a as $k => $v) {
        if (in_array($k, ['name', 'code', 'label', 'line', 'owner', 'system_key', 'active'], true)) {
            continue;
        }
        $out[$k] = is_array($v) ? ledger_figures_only($v) : $v;
    }
    return $out;
}

/* ── Period close ────────────────────────────────────────────────────── */

/**
 * Sign off a period: every tie-out must pass, on both bases; the statement
 * set on the book's default basis is fingerprinted with the journal; the
 * books lock through the period end. Returns the close id. The signer is
 * the host's identity for the person (the host decides who may sign).
 */
function ledger_close_period(PDO $pdo, int $bookId, string $from, string $to, string $signedBy, string $note = ''): int
{
    if (!ledger_is_date($from) || !ledger_is_date($to) || $to < $from) {
        throw new LedgerError('A close needs a period.');
    }
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $from, $to, $signedBy, $note) {
        $b = $pdo->prepare('SELECT default_basis, locked_through FROM ledger.books WHERE id = :b FOR UPDATE');
        $b->execute(['b' => $bookId]);
        $book = $b->fetch(PDO::FETCH_ASSOC);
        if (!$book) {
            throw new LedgerError('No such books.');
        }
        if ($book['locked_through'] !== null && $to <= $book['locked_through']) {
            throw new LedgerError('That period is already closed.');
        }
        if ($book['locked_through'] !== null && $from > ledger_day_after((string) $book['locked_through'])) {
            throw new LedgerError('Close the periods in order: the books are locked through ' . $book['locked_through'] . '.');
        }
        $problems = [];
        foreach (['accrual', 'cash'] as $basis) {
            foreach (ledger_tie_outs($pdo, $bookId, $basis, $from, $to) as $pr) {
                $problems[] = ucfirst($basis) . ': ' . $pr;
            }
        }
        if (function_exists('ledger_assets_behind') && ($behind = ledger_assets_behind($pdo, $bookId, $to))) {
            $problems[] = 'Depreciation is not posted through ' . $to . ' for: ' . implode(', ', $behind) . '.';
        }
        if ($problems) {
            throw new LedgerError('The period cannot be signed: ' . implode(' ', $problems));
        }
        $set = ledger_statement_set($pdo, $bookId, (string) $book['default_basis'], $from, $to);
        $q = $pdo->prepare('INSERT INTO ledger.closes (book_id, period_start, period_end, basis, statements_sha256, journal_sha256, signed_by, note)
                            VALUES (:b, :f, :t, :basis, :s, :j, :by, :n) RETURNING id');
        $q->execute(['b' => $bookId, 'f' => $from, 't' => $to, 'basis' => $book['default_basis'], 's' => $set['sha256'],
                     'j' => ledger_fingerprint($pdo, $bookId, $to), 'by' => mb_substr($signedBy, 0, 120), 'n' => mb_substr($note, 0, 1000)]);
        $id = (int) $q->fetchColumn();
        $pdo->prepare('UPDATE ledger.books SET locked_through = :t WHERE id = :b')->execute(['t' => $to, 'b' => $bookId]);
        return $id;
    });
}

function ledger_day_after(string $d): string
{
    return gmdate('Y-m-d', strtotime($d . ' +1 day'));
}

/**
 * Prove a signed close still stands: regenerate its statement set and
 * journal fingerprint and compare. Returns true when both match.
 */
function ledger_close_verify(PDO $pdo, int $bookId, int $closeId): bool
{
    $q = $pdo->prepare("SELECT * FROM ledger.closes WHERE book_id = :b AND id = :c AND kind = 'close'");
    $q->execute(['b' => $bookId, 'c' => $closeId]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return false;
    }
    $set = ledger_statement_set($pdo, $bookId, (string) $c['basis'], (string) $c['period_start'], (string) $c['period_end']);
    return hash_equals((string) $c['statements_sha256'], $set['sha256'])
        && hash_equals((string) $c['journal_sha256'], ledger_fingerprint($pdo, $bookId, (string) $c['period_end']));
}

/** The book's closes and unlocks, newest first. */
function ledger_closes(PDO $pdo, int $bookId): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.closes WHERE book_id = :b ORDER BY id DESC');
    $q->execute(['b' => $bookId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Reopen closed periods back to a date. Trusted code only (the database
 * refuses it otherwise), with a reason, recorded as its own row.
 */
function ledger_unlock(PDO $pdo, int $bookId, ?string $lockedThrough, string $by, string $reason): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $lockedThrough, $by, $reason) {
        $b = $pdo->prepare('SELECT locked_through FROM ledger.books WHERE id = :b FOR UPDATE');
        $b->execute(['b' => $bookId]);
        $cur = $b->fetchColumn();
        if ($cur === false || $cur === null) {
            throw new LedgerError('Nothing is locked.');
        }
        $pdo->prepare("INSERT INTO ledger.closes (book_id, period_start, period_end, basis, statements_sha256, journal_sha256, signed_by, note, kind, unlocked_to)
                       VALUES (:b, :f, :t, 'accrual', :z, :j, :by, :n, 'unlock', :u)")
            ->execute(['b' => $bookId, 'f' => $lockedThrough ?? $cur, 't' => $cur, 'z' => str_repeat('0', 64),
                       'j' => ledger_fingerprint($pdo, $bookId, (string) $cur), 'by' => mb_substr($by, 0, 120), 'n' => mb_substr(trim($reason), 0, 1000), 'u' => $lockedThrough]);
        $pdo->prepare('UPDATE ledger.books SET locked_through = :t WHERE id = :b')->execute(['t' => $lockedThrough, 'b' => $bookId]);
    });
}


/**
 * Year-end close: on each basis, one entry that zeroes the year's revenue
 * and expense accounts into an equity account (retained earnings for a
 * corporation, owner's or partners' capital otherwise, unless told). The
 * statements leave these entries out of profit and loss and cash flow, so
 * the year still shows what it earned. Must run before the year end is
 * locked. Idempotent per year and basis. Returns the entry ids by basis.
 */
function ledger_year_end_close(PDO $pdo, int $bookId, string $fyEnd, string $actor, ?string $target = null): array
{
    if (!ledger_is_date($fyEnd)) {
        throw new LedgerError('Give the last day of the fiscal year.');
    }
    $b = $pdo->prepare('SELECT * FROM ledger.books WHERE id = :b');
    $b->execute(['b' => $bookId]);
    $book = $b->fetch(PDO::FETCH_ASSOC);
    $fyStart = ledger_fiscal_year_start($pdo, $bookId, $fyEnd);
    if (ledger_day_before(gmdate('Y-m-d', strtotime($fyStart . ' +1 year'))) !== $fyEnd) {
        throw new LedgerError('That is not the last day of a fiscal year (it starts ' . $fyStart . ').');
    }
    if ($book['locked_through'] !== null && $book['locked_through'] >= $fyEnd) {
        throw new LedgerError('The year end is already locked; close the year before signing its last month.');
    }
    $split = $target === null && $book['tax_treatment'] === 'partnership';
    $target = $target ?? (in_array($book['tax_treatment'], ['s_corp', 'c_corp', 'unspecified'], true) ? 'equity.retained' : 'equity.capital');
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $fyStart, $fyEnd, $actor, $target, $split) {
        $out = [];
        foreach (['accrual', 'cash'] as $basis) {
            $lines = [];
            $ni = 0;
            foreach (ledger_activity($pdo, $bookId, $basis, $fyStart, $fyEnd, true) as $a) {
                if (!in_array($a['type'], ['revenue', 'expense'], true) || $a['net'] === 0) {
                    continue;
                }
                $lines[] = ['account' => (int) $a['id'], 'credit' => $a['net'], 'memo' => 'Year-end close'];   // a debit balance is credited away, and the reverse
                $ni -= $a['net'];
            }
            if (!$lines) {
                continue;
            }
            $shares = $split ? ledger_profit_shares($pdo, $bookId, $fyEnd) : [];
            if ($shares) {
                // Each partner's capital takes their share; the rounding cent goes to the largest share.
                $left = $ni;
                arsort($shares);
                $first = array_key_first($shares);
                foreach ($shares as $party => $bp) {
                    $part = $party === $first ? 0 : (int) round($ni * $bp / 10000);
                    $left -= $part;
                    if ($party !== $first) {
                        $lines[] = ['account' => ledger_partner_capital($pdo, $bookId, $party), 'credit' => $part, 'memo' => 'Share of net income, year ending ' . $fyEnd];
                    }
                }
                $lines[] = ['account' => ledger_partner_capital($pdo, $bookId, (string) $first), 'credit' => $left, 'memo' => 'Share of net income, year ending ' . $fyEnd];
            } else {
                $lines[] = ['account' => $target, 'credit' => $ni, 'memo' => 'Net income for the year ending ' . $fyEnd];
            }
            $out[$basis] = ledger_post($pdo, $bookId, [
                'entry_on' => $fyEnd, 'basis' => $basis, 'source_key' => "year_end:$fyEnd:$basis", 'event_type' => 'period.year_end',
                'rule' => 'core.year_end', 'rule_version' => 1, 'memo' => 'Year-end close, ' . $fyStart . ' to ' . $fyEnd . ' (' . $basis . ')',
                'actor' => $actor, 'lines' => $lines,
            ]);
        }
        return $out;
    });
}


/**
 * Set the partners' profit shares from a date (each [party => percent as a
 * string or basis points as int]); they must add up to 100%.
 */
function ledger_profit_shares_set(PDO $pdo, int $bookId, string $from, array $shares, string $actor): void
{
    if (!ledger_is_date($from)) {
        throw new LedgerError('Give the date the shares take effect.');
    }
    $bp = [];
    foreach ($shares as $party => $v) {
        $party = trim((string) $party);
        $n = is_int($v) ? $v : (int) round(((float) str_replace('%', '', (string) $v)) * 100);
        if ($party === '' || $n < 0) {
            throw new LedgerError('Each partner needs a name and a share.');
        }
        if ($n > 0) {
            $bp[$party] = $n;
        }
    }
    if (array_sum($bp) !== 10000) {
        throw new LedgerError(sprintf('The shares add up to %s%%, not 100%%.', number_format(array_sum($bp) / 100, 2)));
    }
    ledger_atomic($pdo, function () use ($pdo, $bookId, $from, $bp, $actor) {
        $ins = $pdo->prepare('INSERT INTO ledger.profit_shares (book_id, owner_party, share_bp, effective_from, actor) VALUES (:b, :p, :s, :f, :a)');
        foreach ($bp as $party => $n) {
            $ins->execute(['b' => $bookId, 'p' => mb_substr($party, 0, 120), 's' => $n, 'f' => $from, 'a' => mb_substr($actor, 0, 120)]);
        }
    });
}

/** The shares in effect on a day: [party => basis points], or [] when none are set. */
function ledger_profit_shares(PDO $pdo, int $bookId, string $on): array
{
    $q = $pdo->prepare('SELECT owner_party, share_bp FROM ledger.profit_shares WHERE book_id = :b AND effective_from =
                          (SELECT MAX(effective_from) FROM ledger.profit_shares WHERE book_id = :b2 AND effective_from <= :d) ORDER BY owner_party');
    $q->execute(['b' => $bookId, 'b2' => $bookId, 'd' => $on]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Each partner's own capital account (made when first needed). */
function ledger_partner_capital(PDO $pdo, int $bookId, string $party): int
{
    $key = 'equity.capital.' . substr(hash('sha256', $party), 0, 16);
    return ledger_account_ensure($pdo, $bookId, ['3010', 'Capital: ' . $party, 'equity', "Partners' capital", 'financing',
        ['key' => $key, 'equity_type' => 'capital', 'owner_party' => $party]]);
}
