<?php
declare(strict_types=1);

// Standalone: php core/agent/tests/run.php
require __DIR__ . '/agent_tests.php';

function check(bool $ok, string $what): void
{
    if (!$ok) {
        throw new RuntimeException($what);
    }
}

function check_same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$pass = 0;
$fail = 0;
foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_agent_')) {
        continue;
    }
    try {
        $fn();
        $pass++;
        echo '.';
    } catch (Throwable $e) {
        $fail++;
        echo "\nFAIL $fn\n  " . $e->getMessage() . "\n  at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
