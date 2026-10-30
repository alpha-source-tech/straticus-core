<?php
declare(strict_types=1);

/**
 * Run the accounting core's tests on their own, with no host product:
 * the core's default tenancy (the ledger.tenant_id and ledger.system
 * settings) stands in for a host's.
 *
 *   LEDGER_TEST_DSN="pgsql:host=127.0.0.1;dbname=ledger_core_test" \
 *   LEDGER_TEST_OWNER=owner LEDGER_TEST_OWNER_PASS=... \
 *   LEDGER_TEST_APP=app LEDGER_TEST_APP_PASS=... \
 *   php core/ledger/tests/run.php [filter]
 *
 * The ledger schema in that database is dropped and rebuilt on every run.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/ledger.php';

$dsn  = getenv('LEDGER_TEST_DSN') ?: 'pgsql:host=127.0.0.1;dbname=ledger_core_test';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$owner = new PDO($dsn, getenv('LEDGER_TEST_OWNER') ?: 'liticus_owner', getenv('LEDGER_TEST_OWNER_PASS') ?: 'devowner', $opts);
$owner->exec('DROP SCHEMA IF EXISTS ledger CASCADE; DROP TABLE IF EXISTS ledger_schema_migrations');
$appRole = getenv('LEDGER_TEST_APP') ?: 'liticus_app';
ledger_migrate($owner, $appRole);
$GLOBALS['lt_app'] = new PDO($dsn, $appRole, getenv('LEDGER_TEST_APP_PASS') ?: 'devapp', $opts);

function lt_scope(?int $tenant, bool $system = false): void
{
    $GLOBALS['lt_app']->prepare("SELECT set_config('ledger.tenant_id', :t, false), set_config('ledger.system', :s, false)")
        ->execute(['t' => $tenant === null ? '' : (string) $tenant, 's' => $system ? '1' : '']);
}

function ledger_test_book(string $tag): array
{
    static $tenant = 1000;
    $tenant++;
    lt_scope($tenant);
    $GLOBALS['lt_tenant'] = $tenant;
    return ['pdo' => $GLOBALS['lt_app'], 'book' => ledger_book_create($GLOBALS['lt_app'], $tenant, "Core test $tag")];
}

function ledger_test_other_tenant(callable $fn): mixed
{
    $prev = $GLOBALS['lt_tenant'];
    lt_scope($prev + 500000);
    try {
        return $fn();
    } finally {
        lt_scope($prev);
    }
}

function check(bool $ok, string $what): void
{
    if (!$ok) {
        throw new RuntimeException($what);
    }
}

function check_same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function check_refused(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (PDOException) {
        return;
    }
    throw new RuntimeException($what . ': expected the database to refuse it, but it was allowed');
}

require __DIR__ . '/core_tests.php';

$filter = $argv[1] ?? '';
$tests = array_values(array_filter(get_defined_functions()['user'], fn($f) => str_starts_with($f, 'test_ledger_') && ($filter === '' || str_contains($f, $filter))));
$fail = [];
foreach ($tests as $fn) {
    try {
        $fn();
        echo '.';
    } catch (Throwable $e) {
        $fail[] = [$fn, $e];
        echo 'F';
    }
}
echo "\n\n";
foreach ($fail as [$fn, $e]) {
    echo "FAIL $fn\n  " . $e->getMessage() . "\n  at " . basename($e->getFile()) . ':' . $e->getLine() . "\n\n";
}
printf("%d passed, %d failed\n", count($tests) - count($fail), count($fail));
exit($fail ? 1 : 0);
