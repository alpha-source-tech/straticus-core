<?php
declare(strict_types=1);

/**
 * Fixed assets and their depreciation schedules.
 *
 * Each month's depreciation is the asset's remaining book value above
 * salvage, spread over the months of life left (straight line), or the
 * declining-balance figure while it is larger. Because each month is worked
 * out from what has actually been posted, a change to the schedule applies
 * from the next unposted month on its own; posted months never move.
 * Depreciation starts in the month the asset goes into service and posts on
 * each month's last day, on both bases (the modified-cash convention).
 */

require_once __DIR__ . '/ledger.php';

const LEDGER_ASSET_METHODS = ['straight_line' => 'Straight line', 'declining_balance' => 'Declining balance'];

function ledger_month(string $d): string
{
    return substr($d, 0, 7) . '-01';
}

function ledger_month_end(string $month): string
{
    return gmdate('Y-m-t', strtotime($month));
}

function ledger_months_between(string $fromMonth, string $toMonth): int
{
    return ((int) substr($toMonth, 0, 4) - (int) substr($fromMonth, 0, 4)) * 12 + (int) substr($toMonth, 5, 2) - (int) substr($fromMonth, 5, 2);
}

function ledger_next_month(string $month): string
{
    return gmdate('Y-m-01', strtotime($month . ' +1 month'));
}

/**
 * Put an asset on the register. $a: name, cost (cents), in_service_on,
 * life_months, method, salvage, db_factor, tag, source_key, actor, and
 * paid_from (an account key or id) when the purchase itself should post
 * here too (debit the asset, credit that account). Leave paid_from out when
 * the purchase is already in the books (a bill, a card). Returns its id.
 */
function ledger_asset_add(PDO $pdo, int $bookId, array $a): int
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $a) {
        $q = $pdo->prepare('SELECT id FROM ledger.assets WHERE book_id = :b AND source_key = :k');
        $q->execute(['b' => $bookId, 'k' => $a['source_key']]);
        if (($id = $q->fetchColumn()) !== false) {
            return (int) $id;
        }
        $cost = (int) ($a['cost'] ?? 0);
        $salvage = (int) ($a['salvage'] ?? 0);
        $life = (int) ($a['life_months'] ?? 0);
        $method = (string) ($a['method'] ?? 'straight_line');
        if ($cost <= 0 || $salvage < 0 || $salvage >= $cost || $life < 1 || $life > 600 || !isset(LEDGER_ASSET_METHODS[$method])
            || !ledger_is_date((string) ($a['in_service_on'] ?? '')) || trim((string) ($a['name'] ?? '')) === '') {
            throw new LedgerError('An asset needs a name, a cost above its salvage value, a date in service and a life of 1 to 600 months.');
        }
        $q = $pdo->prepare('INSERT INTO ledger.assets (book_id, name, asset_account_id, accum_account_id, expense_account_id, cost_cents, salvage_cents,
                                                       in_service_on, method, life_months, db_factor, tag, source_key)
                            VALUES (:b, :n, :aa, :acc, :exp, :c, :s, :on, :m, :l, :f, :t, :k) RETURNING id');
        $q->execute([
            'b' => $bookId, 'n' => mb_substr(trim((string) $a['name']), 0, 120),
            'aa' => ledger_account_ref($pdo, $bookId, $a['asset_account'] ?? 'fixed.equipment'),
            'acc' => ledger_account_ref($pdo, $bookId, $a['accum_account'] ?? 'fixed.accum_depr'),
            'exp' => ledger_account_ref($pdo, $bookId, $a['expense_account'] ?? 'exp.depreciation'),
            'c' => $cost, 's' => $salvage, 'on' => $a['in_service_on'], 'm' => $method, 'l' => $life,
            'f' => (string) ($a['db_factor'] ?? '2.00'), 't' => mb_substr((string) ($a['tag'] ?? ''), 0, 120), 'k' => $a['source_key'],
        ]);
        $id = (int) $q->fetchColumn();
        if (!empty($a['paid_from'])) {
            $acct = $pdo->prepare('SELECT asset_account_id FROM ledger.assets WHERE id = :id');
            $acct->execute(['id' => $id]);
            ledger_post($pdo, $bookId, [
                'entry_on' => $a['in_service_on'], 'basis' => 'both', 'source_key' => $a['source_key'] . '#purchase',
                'event_type' => 'asset.purchased', 'rule' => 'core.asset', 'rule_version' => 1,
                'memo' => 'Bought ' . $a['name'], 'actor' => (string) ($a['actor'] ?? ''),
                'lines' => [['account' => (int) $acct->fetchColumn(), 'debit' => $cost], ['account' => $a['paid_from'], 'credit' => $cost]],
            ]);
        }
        return $id;
    });
}

function ledger_asset(PDO $pdo, int $bookId, int $assetId, bool $lock = false): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.assets WHERE book_id = :b AND id = :a' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute(['b' => $bookId, 'a' => $assetId]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        throw new LedgerError('No such asset in these books.');
    }
    return $a;
}

/** Depreciation posted so far: [total cents, last month posted (Y-m-01) or null, months posted]. */
function ledger_asset_posted(PDO $pdo, array $a): array
{
    $q = $pdo->prepare("SELECT e.source_key, l.credit_cents - l.debit_cents AS amt
                          FROM ledger.entries e JOIN ledger.lines l ON l.entry_id = e.id AND l.account_id = :acc
                         WHERE e.book_id = :b AND e.source_key LIKE :k");
    $q->execute(['acc' => $a['accum_account_id'], 'b' => $a['book_id'], 'k' => str_replace(['%', '_'], ['\%', '\_'], $a['source_key']) . '#dep:%']);
    $total = 0;
    $last = null;
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $total += (int) $r['amt'];
        $m = substr((string) $r['source_key'], -7) . '-01';
        $last = $last === null || $m > $last ? $m : $last;
        $n++;
    }
    return [$total, $last, $n];
}

/**
 * One month's depreciation, from the remaining book value and the months of
 * life left. The last month takes exactly what is left above salvage.
 */
function ledger_asset_month_amount(array $a, int $bookValue, int $monthsLeft): int
{
    $room = $bookValue - (int) $a['salvage_cents'];
    if ($room <= 0) {
        return 0;
    }
    if ($monthsLeft <= 1) {
        return $room;
    }
    $sl = (int) round($room / $monthsLeft);
    if ($a['method'] === 'declining_balance') {
        $db = (int) round($bookValue * (float) $a['db_factor'] / (int) $a['life_months']);
        $sl = max($sl, $db);   // switch to straight line once it gives more
    }
    return min($room, max(0, $sl));
}

/**
 * The schedule from the next unposted month to the end of the asset's life:
 * [month => amount], computed exactly as posting will compute it.
 */
function ledger_asset_schedule(PDO $pdo, array $a, int $limit = 600): array
{
    [$posted, $last] = ledger_asset_posted($pdo, $a);
    $start = ledger_month((string) $a['in_service_on']);
    $month = $last === null ? $start : ledger_next_month($last);
    $value = (int) $a['cost_cents'] - $posted;
    $out = [];
    while (count($out) < $limit) {
        $left = (int) $a['life_months'] - ledger_months_between($start, $month);
        $amt = ledger_asset_month_amount($a, $value, max(1, $left));
        if ($amt <= 0) {
            break;
        }
        $out[$month] = $amt;
        $value -= $amt;
        $month = ledger_next_month($month);
    }
    return $out;
}

/**
 * Post depreciation for every month that has ended on or before $through,
 * for every asset in service. Idempotent per asset and month. Returns the
 * number of entries posted.
 */
function ledger_depreciate_through(PDO $pdo, int $bookId, string $through, string $actor = ''): int
{
    $q = $pdo->prepare('SELECT id FROM ledger.assets WHERE book_id = :b AND disposed_on IS NULL AND in_service_on <= :t ORDER BY id');
    $q->execute(['b' => $bookId, 't' => $through]);
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $assetId) {
        $n += ledger_atomic($pdo, function () use ($pdo, $bookId, $assetId, $through, $actor) {
            $a = ledger_asset($pdo, $bookId, (int) $assetId, true);
            $posted = 0;
            foreach (ledger_asset_schedule($pdo, $a) as $month => $amt) {
                $end = ledger_month_end($month);
                if ($end > $through) {
                    break;
                }
                ledger_post($pdo, $bookId, [
                    'entry_on' => $end, 'basis' => 'both', 'source_key' => $a['source_key'] . '#dep:' . substr($month, 0, 7),
                    'event_type' => 'asset.depreciated', 'rule' => 'core.depreciation', 'rule_version' => 1,
                    'memo' => 'Depreciation, ' . $a['name'] . ', ' . substr($month, 0, 7), 'actor' => $actor,
                    'lines' => [['account' => (int) $a['expense_account_id'], 'debit' => $amt, 'memo' => $a['name']],
                                ['account' => (int) $a['accum_account_id'], 'credit' => $amt, 'memo' => $a['name']]],
                ]);
                $posted++;
            }
            return $posted;
        });
    }
    return $n;
}

/** Assets whose depreciation is not posted through $through: names, for a close to refuse on. */
function ledger_assets_behind(PDO $pdo, int $bookId, string $through): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.assets WHERE book_id = :b AND disposed_on IS NULL AND in_service_on <= :t');
    $q->execute(['b' => $bookId, 't' => $through]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $sched = ledger_asset_schedule($pdo, $a, 1);
        $next = array_key_first($sched);
        if ($next !== null && ledger_month_end($next) <= $through) {
            $out[] = $a['name'];
        }
    }
    return $out;
}

/**
 * Change an asset's schedule (life, salvage, method). The change applies
 * from the next unposted month; it is recorded with who, why, and the
 * schedule before and after. Returns the first month it affects.
 */
function ledger_asset_change(PDO $pdo, int $bookId, int $assetId, array $new, string $reason, string $actor): string
{
    return ledger_atomic($pdo, function () use ($pdo, $bookId, $assetId, $new, $reason, $actor) {
        $a = ledger_asset($pdo, $bookId, $assetId, true);
        if ($a['disposed_on'] !== null) {
            throw new LedgerError('That asset was disposed of.');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new LedgerError('Say why the schedule is changing.');
        }
        [$posted, $last, $n] = ledger_asset_posted($pdo, $a);
        $after = [
            'method' => (string) ($new['method'] ?? $a['method']),
            'life_months' => (int) ($new['life_months'] ?? $a['life_months']),
            'salvage_cents' => (int) ($new['salvage'] ?? $a['salvage_cents']),
            'db_factor' => (string) ($new['db_factor'] ?? $a['db_factor']),
        ];
        $before = ['method' => $a['method'], 'life_months' => (int) $a['life_months'], 'salvage_cents' => (int) $a['salvage_cents'], 'db_factor' => (string) $a['db_factor']];
        $start = ledger_month((string) $a['in_service_on']);
        $elapsed = $last === null ? 0 : ledger_months_between($start, $last) + 1;
        if (!isset(LEDGER_ASSET_METHODS[$after['method']]) || $after['life_months'] <= $elapsed || $after['life_months'] > 600) {
            throw new LedgerError(sprintf('The life must be longer than the %d month(s) already depreciated.', $elapsed));
        }
        if ($after['salvage_cents'] < 0 || $after['salvage_cents'] > (int) $a['cost_cents'] - $posted) {
            throw new LedgerError('Salvage value cannot be more than the asset\'s book value now.');
        }
        $eff = $last === null ? $start : ledger_next_month($last);
        $b = $pdo->prepare('SELECT locked_through FROM ledger.books WHERE id = :b');
        $b->execute(['b' => $bookId]);
        $locked = $b->fetchColumn();
        if ($locked && $eff <= $locked) {
            $eff = ledger_next_month(ledger_month((string) $locked));
        }
        $pdo->prepare('INSERT INTO ledger.asset_changes (book_id, asset_id, effective_month, before, after, reason, actor) VALUES (:b, :a, :e, :bf, :af, :r, :who)')
            ->execute(['b' => $bookId, 'a' => $assetId, 'e' => $eff, 'bf' => json_encode($before), 'af' => json_encode($after),
                       'r' => mb_substr(trim($reason), 0, 500), 'who' => mb_substr($actor, 0, 120)]);
        $pdo->prepare('UPDATE ledger.assets SET method = :m, life_months = :l, salvage_cents = :s, db_factor = :f WHERE id = :id')
            ->execute(['m' => $after['method'], 'l' => $after['life_months'], 's' => $after['salvage_cents'], 'f' => $after['db_factor'], 'id' => $assetId]);
        return $eff;
    });
}

/**
 * Dispose of an asset: depreciation through the month before, then the
 * asset and its accumulated depreciation come off the books, any proceeds
 * come in, and the difference is a gain or loss.
 */
function ledger_asset_dispose(PDO $pdo, int $bookId, int $assetId, string $on, int $proceeds, int|string $cashAccount, string $actor): void
{
    ledger_atomic($pdo, function () use ($pdo, $bookId, $assetId, $on, $proceeds, $cashAccount, $actor) {
        $a = ledger_asset($pdo, $bookId, $assetId, true);
        if ($a['disposed_on'] !== null) {
            return;
        }
        if (!ledger_is_date($on) || $on < (string) $a['in_service_on'] || $proceeds < 0) {
            throw new LedgerError('A disposal needs a date on or after the asset went into service, and proceeds of zero or more.');
        }
        $prevEnd = gmdate('Y-m-d', strtotime(ledger_month($on) . ' -1 day'));
        if ($prevEnd >= (string) $a['in_service_on']) {
            ledger_depreciate_through($pdo, $bookId, $prevEnd, $actor);
        }
        [$accum] = ledger_asset_posted($pdo, $a);
        $book = (int) $a['cost_cents'] - $accum;
        $diff = $proceeds - $book;
        $lines = [['account' => (int) $a['asset_account_id'], 'credit' => (int) $a['cost_cents'], 'memo' => $a['name']]];
        if ($accum > 0) {
            $lines[] = ['account' => (int) $a['accum_account_id'], 'debit' => $accum, 'memo' => $a['name']];
        }
        if ($proceeds > 0) {
            $lines[] = ['account' => $cashAccount, 'debit' => $proceeds];
        }
        if ($diff > 0) {
            $lines[] = ['account' => ledger_account_ensure($pdo, $bookId, ['4950', 'Gain on disposal of assets', 'revenue', 'Other income', 'none', ['key' => 'gain.disposal']]), 'credit' => $diff];
        } elseif ($diff < 0) {
            $lines[] = ['account' => ledger_account_ensure($pdo, $bookId, ['6950', 'Loss on disposal of assets', 'expense', 'Other income and expense', 'none', ['key' => 'exp.disposal_loss']]), 'debit' => -$diff];
        }
        $entry = ledger_post($pdo, $bookId, [
            'entry_on' => $on, 'basis' => 'both', 'source_key' => $a['source_key'] . '#dispose', 'event_type' => 'asset.disposed',
            'rule' => 'core.asset', 'rule_version' => 1, 'memo' => 'Disposed of ' . $a['name'], 'actor' => $actor, 'lines' => $lines,
        ]);
        $pdo->prepare('UPDATE ledger.assets SET disposed_on = :d, disposal_entry_id = :e WHERE id = :id')->execute(['d' => $on, 'e' => $entry, 'id' => $assetId]);
    });
}

/** The register: each asset with cost, depreciation to date, book value and what is left of its schedule. */
function ledger_asset_register(PDO $pdo, int $bookId): array
{
    $q = $pdo->prepare('SELECT * FROM ledger.assets WHERE book_id = :b ORDER BY disposed_on IS NOT NULL, in_service_on, id');
    $q->execute(['b' => $bookId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        [$posted, $last, $n] = ledger_asset_posted($pdo, $a);
        $sched = $a['disposed_on'] === null ? ledger_asset_schedule($pdo, $a) : [];
        $c = $pdo->prepare('SELECT * FROM ledger.asset_changes WHERE asset_id = :a ORDER BY id');
        $c->execute(['a' => $a['id']]);
        $out[] = $a + [
            'depreciated' => $posted, 'book_value' => (int) $a['cost_cents'] - $posted, 'last_posted' => $last,
            'months_posted' => $n, 'remaining' => $sched, 'changes' => $c->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
    return $out;
}
