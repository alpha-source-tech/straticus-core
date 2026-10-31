<?php
declare(strict_types=1);

/**
 * How a set of books begins.
 *
 * A start-up opens at zero on its first day: nothing to import, at most the
 * owner's first contribution. An existing business brings its balances in
 * as of the day before its start date, in one opening entry, and the days
 * before the start are locked so the history the old system holds is never
 * mixed with what these books record.
 *
 * Receivables, payables and money held for others never come in as bare
 * balances: open invoices and bills are issued as live documents (so they
 * are still collected and paid here, and the control accounts tie out), and
 * the host brings each client's held balance through its own ledger. The
 * opening entry then carries every other balance, with any difference the
 * balances do not explain put to opening-balance equity and reported, never
 * hidden.
 */

/** Where these books stand: origin, started_on, and whether setup is done. */
function ledger_origin(PDO $pdo, int $bookId): array
{
    $q = $pdo->prepare('SELECT origin, started_on, default_basis, entity_form, tax_treatment, locked_through FROM ledger.books WHERE id = :b');
    $q->execute(['b' => $bookId]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $r['ready'] = ($r['origin'] ?? 'unset') !== 'unset';
    return $r;
}

/**
 * A start-up: the books begin on $startedOn at zero. $capital, if given,
 * is the owner's first contribution into operating cash on that day
 * (cents), credited to the treatment's capital or contributions account.
 */
function ledger_open_startup(PDO $pdo, int $bookId, string $startedOn, int $capital = 0, string $actor = ''): void
{
    if (!ledger_is_date($startedOn)) {
        throw new LedgerError('A start date is needed.');
    }
    if ($capital < 0) {
        throw new LedgerError('An opening contribution cannot be negative.');
    }
    ledger_atomic($pdo, function () use ($pdo, $bookId, $startedOn, $capital, $actor) {
        $o = ledger_origin($pdo, $bookId);
        if ($o['ready']) {
            throw new LedgerError('These books are already set up.');
        }
        // Books that already carry the business's own records (a host that synced before setup)
        // can still be a start-up: the start date must be on or before the first entry.
        $first = $pdo->query('SELECT MIN(entry_on) FROM ledger.entries WHERE book_id = ' . (int) $bookId)->fetchColumn();
        if ($first !== false && $first !== null && $startedOn > (string) $first) {
            throw new LedgerError('These books already hold an entry dated ' . $first . '; the start date must be on or before it.');
        }
        $pdo->prepare("UPDATE ledger.books SET origin = 'startup', started_on = :d WHERE id = :b")->execute(['d' => $startedOn, 'b' => $bookId]);
        if ($capital > 0) {
            ledger_post($pdo, $bookId, [
                'entry_on' => $startedOn, 'basis' => 'both', 'source_key' => 'opening:capital', 'event_type' => 'opening.capital',
                'rule' => 'core.opening', 'rule_version' => 1, 'memo' => "Owner's opening contribution", 'actor' => $actor,
                'lines' => [['account' => 'cash.operating', 'debit' => $capital], ['account' => ledger_opening_capital_account($pdo, $bookId), 'credit' => $capital]],
            ]);
        }
    });
    ledger_cache_clear();
}

/** The account an owner's contribution goes to under the book's treatment. */
function ledger_opening_capital_account(PDO $pdo, int $bookId): string
{
    $q = $pdo->prepare("SELECT system_key FROM ledger.accounts WHERE book_id = :b AND active AND system_key = ANY (:ks)");
    $q->execute(['b' => $bookId, 'ks' => '{equity.contributions,equity.capital,equity.apic,equity.stock}']);
    $have = $q->fetchAll(PDO::FETCH_COLUMN);
    foreach (['equity.contributions', 'equity.capital', 'equity.apic', 'equity.stock'] as $k) {
        if (in_array($k, $have, true)) {
            return $k;
        }
    }
    return 'equity.opening';
}

/**
 * An existing business. $startedOn is the first day these books cover; the
 * opening position is as of the day before.
 *
 * $opening:
 *   balances   [['account' => key or id, 'debit' => cents] | ['account' => ..., 'credit' => cents]]
 *              Every balance-sheet account except receivables, payables and
 *              money held for others (see below). Revenue and expense
 *              accounts are refused: the old system's year is closed into
 *              equity, not continued here.
 *   receivables [['party' => 'client:1', 'ref' => 'INV-0041', 'issued_on' => date, 'due_on' => date|null,
 *                 'lines' => [['account' => revenue key, 'amount' => cents, 'memo' => '']], 'source_key' => unique]]
 *   payables    the same shape, lines to expense or asset accounts
 *   assets      [['name', 'cost', 'in_service_on', 'life_months', 'method', 'salvage', 'accumulated' => cents, 'source_key', 'asset_account'?]]
 *               Each asset's cost and accumulated depreciation are part of
 *               the opening position; do not repeat them in balances.
 *   held        [['account' => restricted cash key, 'liability' => liability key, 'party' => 'client:1', 'amount' => cents]]
 *               Money held for others, per party, so the liability has a party and ties out.
 *
 * Returns ['entry_id' => int, 'plug' => cents (positive = credit to opening
 * equity), 'documents' => n, 'assets' => n, 'closed' => close id].
 */
function ledger_open_existing(PDO $pdo, int $bookId, string $startedOn, array $opening, string $actor = ''): array
{
    if (!ledger_is_date($startedOn)) {
        throw new LedgerError('A start date is needed.');
    }
    $asOf = ledger_day_before($startedOn);
    $out = ledger_atomic($pdo, function () use ($pdo, $bookId, $startedOn, $asOf, $opening, $actor) {
        $o = ledger_origin($pdo, $bookId);
        if ($o['ready']) {
            throw new LedgerError('These books are already set up.');
        }
        // The host may have posted parts of the opening position itself, dated the day before the
        // start (money held for others, through its own ledger). Anything earlier is history that
        // belongs to the old books.
        $q = $pdo->prepare('SELECT COUNT(*) FROM ledger.entries WHERE book_id = :b AND entry_on < :d');
        $q->execute(['b' => $bookId, 'd' => $asOf]);
        if ((int) $q->fetchColumn() > 0) {
            throw new LedgerError('These books already hold entries dated before ' . $asOf . '; choose a start date on or before the earliest entry, or start from zero.');
        }
        $accounts = [];
        $q = $pdo->prepare('SELECT id, system_key, type, control, accrual_only, cash_flow_class FROM ledger.accounts WHERE book_id = :b');
        $q->execute(['b' => $bookId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $accounts[(int) $a['id']] = $a;
            if ($a['system_key'] !== null && $a['system_key'] !== '') {
                $accounts[$a['system_key']] = $a;
            }
        }
        $acct = function ($ref) use ($accounts): array {
            $a = $accounts[is_numeric($ref) ? (int) $ref : (string) $ref] ?? null;
            if (!$a) {
                throw new LedgerError('No account ' . $ref . ' in the chart.');
            }
            return $a;
        };

        // 1. Open documents, dated as issued (before the start), so they are collected and paid here.
        $docs = 0;
        foreach (['receivables' => 'receivable', 'payables' => 'payable'] as $k => $kind) {
            foreach ($opening[$k] ?? [] as $i => $d) {
                $issued = (string) ($d['issued_on'] ?? $asOf);
                if (!ledger_is_date($issued) || $issued > $asOf) {
                    throw new LedgerError(ucfirst($kind) . ' ' . ($d['ref'] ?? $i) . ' must be dated on or before ' . $asOf . '.');
                }
                ledger_document_issue($pdo, $bookId, [
                    'kind' => $kind, 'party' => (string) ($d['party'] ?? ''), 'ref' => (string) ($d['ref'] ?? ''), 'issued_on' => $issued,
                    'due_on' => ledger_is_date((string) ($d['due_on'] ?? '')) ? $d['due_on'] : null,
                    'source_key' => (string) ($d['source_key'] ?? ('opening:' . $kind . ':' . $i)), 'actor' => $actor,
                    'rule' => 'core.opening', 'rule_version' => 1, 'lines' => $d['lines'] ?? [],
                ]);
                $docs++;
            }
        }

        // 2. Fixed assets: on the register with their cost, and depreciation to date in one catch-up entry.
        $nAssets = 0;
        foreach ($opening['assets'] ?? [] as $i => $a) {
            $accum = (int) ($a['accumulated'] ?? 0);
            $cost = (int) ($a['cost'] ?? 0);
            if ($accum < 0 || $accum >= $cost) {
                throw new LedgerError('Asset ' . ($a['name'] ?? $i) . ': accumulated depreciation must be less than its cost.');
            }
            $key = (string) ($a['source_key'] ?? ('opening:asset:' . $i));
            $assetId = ledger_asset_add($pdo, $bookId, ['name' => $a['name'] ?? '', 'cost' => $cost, 'in_service_on' => $a['in_service_on'] ?? '',
                'life_months' => $a['life_months'] ?? 0, 'method' => $a['method'] ?? 'straight_line', 'salvage' => $a['salvage'] ?? 0,
                'source_key' => $key, 'actor' => $actor] + array_intersect_key($a, array_flip(['asset_account', 'accum_account', 'expense_account', 'db_factor', 'tag'])));
            if ($accum > 0) {
                // Dated the month before the start and keyed like a monthly posting, so the
                // schedule continues from the start month.
                $ar = $pdo->prepare('SELECT * FROM ledger.assets WHERE id = :id');
                $ar->execute(['id' => $assetId]);
                $row = $ar->fetch(PDO::FETCH_ASSOC);
                ledger_post($pdo, $bookId, [
                    'entry_on' => $asOf, 'basis' => 'both', 'source_key' => $key . '#dep:' . substr($asOf, 0, 7), 'event_type' => 'asset.depreciated',
                    'rule' => 'core.opening', 'rule_version' => 1, 'memo' => 'Depreciation to ' . $asOf . ' (opening balance)', 'actor' => $actor,
                    'lines' => [['account' => (int) $row['expense_account_id'], 'debit' => $accum], ['account' => (int) $row['accum_account_id'], 'credit' => $accum]],
                ]);
            }
            $nAssets++;
        }

        // 3. Money held for others: restricted cash against a liability with a party.
        $heldLines = [];
        foreach ($opening['held'] ?? [] as $h) {
            $amt = (int) ($h['amount'] ?? 0);
            if ($amt <= 0 || (string) ($h['party'] ?? '') === '') {
                throw new LedgerError('Money held for others needs a party and an amount.');
            }
            $heldLines[] = ['account' => $h['account'] ?? 't.held_cash', 'debit' => $amt];
            $heldLines[] = ['account' => $h['liability'] ?? 't.held', 'credit' => $amt, 'party' => (string) $h['party']];
        }

        // 4. Everything else. Targets are the balances given; what steps 1 to 3 already posted
        //    (asset cost, accumulated depreciation, document revenue and expense) is left alone,
        //    so the opening entry carries only what is not yet on the books.
        $targets = [];
        foreach ($opening['balances'] ?? [] as $b) {
            $a = $acct($b['account'] ?? '');
            if (in_array($a['type'], ['revenue', 'expense'], true)) {
                throw new LedgerError('Revenue and expense balances do not come in: the old year is closed into equity.');
            }
            if (!in_array($a['control'], [null, '', 'none'], true)) {
                throw new LedgerError(($a['system_key'] ?: $a['id']) . ' is a control account: bring its balance in as open invoices, bills or held money, not as a figure.');
            }
            $net = (int) ($b['debit'] ?? 0) - (int) ($b['credit'] ?? 0);
            $targets[(int) $a['id']] = ($targets[(int) $a['id']] ?? 0) + $net;
        }
        // Asset cost sits in the opening position whether or not the host listed it.
        foreach ($opening['assets'] ?? [] as $a) {
            $ref = $a['asset_account'] ?? 'fixed.equipment';
            $id = (int) $acct($ref)['id'];
            $targets[$id] = ($targets[$id] ?? 0) + (int) $a['cost'];
            $accId = (int) $acct($a['accum_account'] ?? 'fixed.accum_depr')['id'];
            $targets[$accId] = ($targets[$accId] ?? 0) - (int) ($a['accumulated'] ?? 0);
        }
        $lines = $heldLines;
        $plug = 0;
        $posted = ledger_balances_at($pdo, $bookId, $asOf);
        foreach ($targets as $id => $want) {
            $have = $posted[$id] ?? 0;
            $delta = $want - $have;
            if ($delta === 0) {
                continue;
            }
            $lines[] = $delta > 0 ? ['account' => $id, 'debit' => $delta] : ['account' => $id, 'credit' => -$delta];
        }
        $debits = array_sum(array_map(fn($l) => (int) ($l['debit'] ?? 0), $lines));
        $credits = array_sum(array_map(fn($l) => (int) ($l['credit'] ?? 0), $lines));
        $plug = $debits - $credits;   // positive: more assets than the balances explain, so equity goes up
        if ($plug !== 0) {
            $lines[] = $plug > 0 ? ['account' => 'equity.opening', 'credit' => $plug] : ['account' => 'equity.opening', 'debit' => -$plug];
        }
        $entryId = 0;
        if (count($lines) >= 2) {
            $entryId = ledger_post($pdo, $bookId, [
                'entry_on' => $asOf, 'basis' => 'both', 'source_key' => 'opening:balances', 'event_type' => 'opening.balances',
                'rule' => 'core.opening', 'rule_version' => 1, 'memo' => 'Opening balances as of ' . $asOf, 'actor' => $actor, 'lines' => $lines,
            ]);
        }
        // The documents' revenue and expense belong to the old system's year. They exist on the
        // accrual basis only (a receivable is accrual-only), so they are closed into opening equity
        // on that basis only; on the cash basis the old work becomes income when it is collected.
        $pl = 0;
        $close = [];
        foreach ($posted as $id => $bal) {
            if (in_array($accounts[$id]['type'] ?? '', ['revenue', 'expense'], true) && $bal !== 0) {
                $close[] = $bal > 0 ? ['account' => $id, 'credit' => $bal] : ['account' => $id, 'debit' => -$bal];
                $pl -= $bal;   // a credit balance (revenue) raises equity
            }
        }
        if ($close) {
            $close[] = $pl > 0 ? ['account' => 'equity.opening', 'credit' => $pl] : ['account' => 'equity.opening', 'debit' => -$pl];
            ledger_post($pdo, $bookId, [
                'entry_on' => $asOf, 'basis' => 'accrual', 'source_key' => 'opening:documents', 'event_type' => 'opening.documents',
                'rule' => 'core.opening', 'rule_version' => 1, 'memo' => 'Open invoices and bills as of ' . $asOf . ', closed into opening equity', 'actor' => $actor, 'lines' => $close,
            ]);
            $plug += $pl;
        }
        $pdo->prepare("UPDATE ledger.books SET origin = 'existing', started_on = :d WHERE id = :b")->execute(['d' => $startedOn, 'b' => $bookId]);
        return ['entry_id' => $entryId, 'plug' => $plug, 'documents' => $docs, 'assets' => $nAssets, 'as_of' => $asOf];
    });
    ledger_cache_clear();
    // 5. Lock the days before the start: the opening position is signed like any other period.
    $first = $pdo->prepare('SELECT MIN(entry_on) FROM ledger.entries WHERE book_id = :b');
    $first->execute(['b' => $bookId]);
    $from = (string) ($first->fetchColumn() ?: $asOf);
    $out['closed'] = ledger_close_period($pdo, $bookId, min($from, $asOf), $asOf, $actor !== '' ? $actor : 'opening', 'Opening balances brought in; the old system holds the history before ' . $startedOn . '.');
    ledger_cache_clear();
    return $out;
}

/** Debit-positive balance of every account with any posting on or before a day (both bases, accrual view). */
function ledger_balances_at(PDO $pdo, int $bookId, string $asOf): array
{
    [$src, $p] = ledger_sum_source($bookId, 'accrual', null, $asOf);
    $q = $pdo->prepare("SELECT account_id, SUM(debit_cents - credit_cents) AS bal FROM $src s GROUP BY account_id");
    $q->execute($p);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['account_id']] = (int) $r['bal'];
    }
    return $out;
}

/**
 * Read a trial balance CSV (columns: account or code, name?, debit, credit;
 * amounts in dollars) into the balances shape. Rows for control accounts
 * are returned apart so the host can ask for the detail behind them.
 *
 * @return array{balances: array, control: array, unknown: array}
 */
function ledger_opening_parse_csv(PDO $pdo, int $bookId, string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $fh = fopen('php://memory', 'r+b');
    fwrite($fh, $csv);
    rewind($fh);
    $byCode = [];
    $q = $pdo->prepare('SELECT id, code, system_key, name, control FROM ledger.accounts WHERE book_id = :b');
    $q->execute(['b' => $bookId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $byCode[strtoupper((string) $a['code'])] = $a;
        if ($a['system_key']) {
            $byCode[strtolower((string) $a['system_key'])] = $a;
        }
    }
    $head = null;
    $balances = $control = $unknown = [];
    $money = fn(string $s) => (int) round((float) preg_replace('/[^0-9.\-]/', '', str_replace(['(', ')'], ['-', ''], $s)) * 100);
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0 || str_starts_with(trim((string) $row[0]), '#')) {
            continue;
        }
        if ($head === null) {
            $head = array_map(fn($h) => strtolower(trim((string) $h)), $row);
            if (!in_array('debit', $head, true) || !in_array('credit', $head, true)) {
                throw new LedgerError('The file needs account, debit and credit columns.');
            }
            continue;
        }
        $r = [];
        foreach ($head as $k => $col) {
            $r[$col] = trim((string) ($row[$k] ?? ''));
        }
        $ref = (string) ($r['account'] ?? $r['code'] ?? '');
        if (strtolower($ref) === 'total' || strtolower($ref) === 'totals') {
            continue;
        }
        $a = $byCode[strtoupper($ref)] ?? $byCode[strtolower($ref)] ?? null;
        $d = $money((string) ($r['debit'] ?? ''));
        $c = $money((string) ($r['credit'] ?? ''));
        if ($d === 0 && $c === 0) {
            continue;
        }
        if (!$a) {
            $unknown[] = ['account' => $ref, 'name' => (string) ($r['name'] ?? ''), 'debit' => $d, 'credit' => $c];
        } elseif (!in_array($a['control'], [null, '', 'none'], true)) {
            $control[] = ['account' => $a['system_key'] ?: $a['id'], 'name' => $a['name'], 'control' => $a['control'], 'debit' => $d, 'credit' => $c];
        } else {
            $balances[] = ['account' => (int) $a['id'], 'debit' => $d, 'credit' => $c];
        }
    }
    return ['balances' => $balances, 'control' => $control, 'unknown' => $unknown];
}
