<?php
declare(strict_types=1);

/**
 * Cash forecast: will the business have the cash it needs in the next 30,
 * 60 and 90 days? Forward-looking and separate from the cash flow statement.
 *
 * - Opening balance: spendable cash (accounts of class 'cash'), never
 *   restricted cash, so client trust money is always left out.
 * - Inflows: what open receivables still owe, each moved by how late that
 *   party has paid before (its average days past due).
 * - Outflows: open payables by due date, plus recurring costs the host
 *   supplies (rent, payroll, subscriptions).
 * - Confidence: each receivable carries a band from the spread of that
 *   party's past delays (at least 3 payments), else plus or minus 30%; the
 *   band for a window is the amount-weighted average, capped at 50%.
 * - Risks, the five largest: late payers pushing money out of a window,
 *   a low band that runs out of cash, thin data, stale cash.
 *
 * It always reads accrual data (what is owed), whatever basis the business
 * reports on.
 */

require_once __DIR__ . '/ledger.php';

/** How late a party pays: [mean days past due, standard deviation, payments measured]. */
function ledger_party_lag(PDO $pdo, int $bookId, string $kind, string $party): array
{
    $q = $pdo->prepare("SELECT s.settled_on - coalesce(d.due_on, d.issued_on) AS lag
                          FROM ledger.settlements s JOIN ledger.documents d ON d.id = s.document_id
                         WHERE d.book_id = :b AND d.kind = :k AND d.party = :p AND s.reversed_at IS NULL
                         ORDER BY s.settled_on DESC LIMIT 12");
    $q->execute(['b' => $bookId, 'k' => $kind, 'p' => $party]);
    $lags = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $n = count($lags);
    if ($n === 0) {
        return [0.0, 0.0, 0];
    }
    $mean = array_sum($lags) / $n;
    $var = $n > 1 ? array_sum(array_map(fn($l) => ($l - $mean) ** 2, $lags)) / ($n - 1) : 0.0;
    return [$mean, sqrt($var), $n];
}

/**
 * @param array $recurring each: name, amount (cents, money out), day (day of
 *        month it falls on, 1-31) or every_days with next_on
 * @return array{as_of:string, opening:?int, opening_as_of:?string, windows:array, items:array, risks:array, sources:array}
 */
function ledger_cash_forecast(PDO $pdo, int $bookId, string $asOf, array $recurring = []): array
{
    $openingKnown = true;
    $cash = 0;
    $lastCash = null;
    $q = $pdo->prepare("SELECT coalesce(SUM(l.debit_cents - l.credit_cents), 0) AS bal, MAX(e.entry_on) AS last_on
                          FROM ledger.lines l JOIN ledger.entries e ON e.id = l.entry_id JOIN ledger.accounts a ON a.id = l.account_id
                         WHERE l.book_id = :b AND a.cash_flow_class = 'cash' AND e.basis IN ('both','accrual') AND e.entry_on <= :t");
    $q->execute(['b' => $bookId, 't' => $asOf]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row['last_on'] === null) {
        $openingKnown = false;
    } else {
        $cash = (int) $row['bal'];
        $lastCash = (string) $row['last_on'];
    }
    $end = fn(int $days) => gmdate('Y-m-d', strtotime("$asOf +$days days"));
    $windows = [
        ['label' => '0–30 days', 'from' => $asOf, 'to' => $end(30), 'in' => 0, 'out' => 0, 'band_w' => 0.0, 'band_amt' => 0],
        ['label' => '31–60 days', 'from' => $end(31), 'to' => $end(60), 'in' => 0, 'out' => 0, 'band_w' => 0.0, 'band_amt' => 0],
        ['label' => '61–90 days', 'from' => $end(61), 'to' => $end(90), 'in' => 0, 'out' => 0, 'band_w' => 0.0, 'band_amt' => 0],
    ];
    $windowOf = function (string $d) use ($windows): ?int {
        foreach ($windows as $i => $w) {
            if ($d <= $w['to']) {
                return $i;
            }
        }
        return null;
    };
    $items = [];
    $risks = [];
    $thin = [];
    // Open receivables and payables: what each still owes, when it is expected.
    $docs = $pdo->prepare("SELECT d.* FROM ledger.documents d WHERE d.book_id = :b AND d.status = 'open' AND d.issued_on <= :t ORDER BY d.id");
    $docs->execute(['b' => $bookId, 't' => $asOf]);
    foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $open = ledger_document_open($pdo, (int) $d['id']);
        if ($open <= 0) {
            continue;
        }
        $due = (string) ($d['due_on'] ?? '') ?: gmdate('Y-m-d', strtotime($d['issued_on'] . ' +30 days'));
        if ($d['kind'] === 'receivable') {
            [$mean, $sd, $n] = ledger_party_lag($pdo, $bookId, 'receivable', (string) $d['party']);
            $lag = (int) round(max(0, $mean));
            $expected = max($asOf, gmdate('Y-m-d', strtotime("$due +$lag days")));
            $band = $n >= 3 ? ($mean > 0 ? min(0.5, $sd / max(1.0, $mean)) : min(0.5, $sd / 30)) : 0.3;
            if ($n < 3) {
                $thin[$d['party']] = $n;
            }
            $i = $windowOf($expected);
            $items[] = ['kind' => 'in', 'ref' => $d['ref'], 'party' => $d['party'], 'amount' => $open, 'due_on' => $due, 'expected_on' => $expected,
                        'window' => $i, 'band' => round($band * 100, 1), 'payments_measured' => $n];
            if ($i !== null) {
                $windows[$i]['in'] += $open;
                $windows[$i]['band_w'] += $band * $open;
                $windows[$i]['band_amt'] += $open;
            }
            $dueWin = $windowOf(max($asOf, $due));
            if ($lag >= 7 && $dueWin !== $i && $open >= 10000) {
                $risks[] = ['severity' => $open, 'kind' => 'late_payer',
                            'text' => sprintf('%s usually pays %d days late: %s on %s moves from %s to %s.', $d['party'], $lag, ledger_money($open), $d['ref'],
                                $dueWin === null ? 'later' : $windows[$dueWin]['label'], $i === null ? 'beyond 90 days' : $windows[$i]['label'])];
            }
        } else {
            $i = $windowOf(max($asOf, $due));
            $items[] = ['kind' => 'out', 'ref' => $d['ref'], 'party' => $d['party'], 'amount' => $open, 'due_on' => $due, 'expected_on' => max($asOf, $due), 'window' => $i];
            if ($i !== null) {
                $windows[$i]['out'] += $open;
            }
        }
    }
    // Recurring costs in each window.
    foreach ($recurring as $r) {
        $amt = (int) ($r['amount'] ?? 0);
        if ($amt <= 0) {
            continue;
        }
        $dates = [];
        $from = ledger_is_date((string) ($r['from'] ?? '')) ? (string) $r['from'] : $asOf;   // a cost that starts later
        if (ledger_is_date((string) ($r['once_on'] ?? ''))) {
            if ($r['once_on'] >= $asOf && $r['once_on'] <= $end(90)) {
                $dates[] = (string) $r['once_on'];   // a one-time amount
            }
        } elseif (!empty($r['every_days']) && ledger_is_date((string) ($r['next_on'] ?? ''))) {
            for ($d = (string) $r['next_on']; $d <= $end(90); $d = gmdate('Y-m-d', strtotime($d . ' +' . (int) $r['every_days'] . ' days'))) {
                if ($d >= $asOf) {
                    $dates[] = $d;
                }
            }
        } elseif (!empty($r['day'])) {
            for ($m = 0; $m <= 3; $m++) {
                $month = gmdate('Y-m-01', strtotime(substr($asOf, 0, 7) . "-01 +$m month"));
                $d = substr($month, 0, 8) . sprintf('%02d', min((int) $r['day'], (int) gmdate('t', strtotime($month))));
                if ($d >= $asOf && $d <= $end(90)) {
                    $dates[] = $d;
                }
            }
        }
        $dir = ($r['direction'] ?? 'out') === 'in' ? 'in' : 'out';   // money coming in (a loan's proceeds), or going out
        foreach ($dates as $d) {
            if ($d < $from) {
                continue;
            }
            $i = $windowOf($d);
            $items[] = ['kind' => $dir, 'ref' => (string) ($r['name'] ?? 'Recurring'), 'party' => '', 'amount' => $amt, 'due_on' => $d, 'expected_on' => $d, 'window' => $i, 'band' => 0];
            if ($i !== null) {
                $windows[$i][$dir] += $amt;
            }
        }
    }
    // Running position, with bands.
    $run = $cash;
    $runLow = $cash;
    foreach ($windows as $i => &$w) {
        $band = $w['band_amt'] > 0 ? min(0.5, $w['band_w'] / $w['band_amt']) : 0.0;
        $w['band_pct'] = round($band * 100, 1);
        $w['net'] = $w['in'] - $w['out'];
        $w['net_low'] = (int) round($w['in'] * (1 - $band)) - $w['out'];
        $w['net_high'] = (int) round($w['in'] * (1 + $band)) - $w['out'];
        $run += $w['net'];
        $runLow += $w['net_low'];
        $w['cash_end'] = $openingKnown ? $run : null;
        $w['cash_end_low'] = $openingKnown ? $runLow : null;
        unset($w['band_w'], $w['band_amt']);
        if ($openingKnown && $runLow < 0) {
            $risks[] = ['severity' => -$runLow, 'kind' => 'shortfall',
                        'text' => sprintf('On the low band, cash runs short by %s by the end of %s.', ledger_money(-$runLow), $w['label'])];
        }
    }
    unset($w);
    // The biggest single outflow in a window that the low band cannot cover before it lands.
    usort($items, fn($a, $b) => [$a['expected_on'], $a['kind']] <=> [$b['expected_on'], $b['kind']]);
    $lowest = null;   // the lowest cash on hand on any day, on the low band
    if ($openingKnown) {
        $pos = $cash;
        $lowest = ['amount' => $cash, 'on' => $asOf];
        $flagged = false;
        foreach ($items as $it) {
            if ($it['window'] === null) {
                continue;
            }
            $pos += $it['kind'] === 'in' ? (int) round($it['amount'] * (1 - ($it['band'] ?? 0) / 100)) : -$it['amount'];
            if ($pos < $lowest['amount']) {
                $lowest = ['amount' => $pos, 'on' => $it['expected_on']];
            }
            if (!$flagged && $it['kind'] === 'out' && $pos < 0) {
                $flagged = true;
                $risks[] = ['severity' => $it['amount'], 'kind' => 'payment_at_risk',
                            'text' => sprintf('%s of %s on %s: on the low band, cash on hand would be %s that day.', $it['ref'], ledger_money($it['amount']), $it['expected_on'], ledger_money($pos))];
            }
        }
    }
    foreach ($thin as $party => $n) {
        $risks[] = ['severity' => 0, 'kind' => 'thin_data', 'text' => sprintf('Only %d payment(s) on record for %s: its band is the default plus or minus 30%%.', $n, $party)];
    }
    if (!$openingKnown) {
        $risks[] = ['severity' => PHP_INT_MAX, 'kind' => 'no_opening', 'text' => 'No cash balance in the books yet: this shows net change only.'];
    } elseif ($lastCash !== null && $lastCash < gmdate('Y-m-d', strtotime("$asOf -7 days"))) {
        $risks[] = ['severity' => 1, 'kind' => 'stale', 'text' => 'Nothing has moved through cash in the books since ' . $lastCash . ': the opening balance may be out of date.'];
    }
    usort($risks, fn($a, $b) => $b['severity'] <=> $a['severity']);
    return [
        'as_of' => $asOf, 'opening' => $openingKnown ? $cash : null, 'opening_as_of' => $lastCash, 'windows' => $windows, 'lowest' => $lowest,
        'items' => $items, 'risks' => array_slice($risks, 0, 5),
    ];
}

function ledger_money(int $cents): string
{
    return ($cents < 0 ? '-$' : '$') . number_format(abs($cents) / 100, 2);
}
