<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Tools\ToolCallHistory;

$failures = 0;
function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertEquals($expected, $actual, string $msg): void {
    global $failures;
    if ($expected !== $actual) { echo "  FAIL  $msg (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

echo "=== hashInputs normalizes key order ===\n";
$h1 = ToolCallHistory::hashInputs('foo', ['a' => 1, 'b' => 2]);
$h2 = ToolCallHistory::hashInputs('foo', ['b' => 2, 'a' => 1]);
assertEquals($h1, $h2, 'same logical inputs in different order produce same hash');

echo "=== hashInputs normalizes nested key order ===\n";
$h1 = ToolCallHistory::hashInputs('foo', ['outer' => ['a' => 1, 'b' => 2]]);
$h2 = ToolCallHistory::hashInputs('foo', ['outer' => ['b' => 2, 'a' => 1]]);
assertEquals($h1, $h2, 'recursive ksort applies to nested arrays');

echo "=== hashInputs differs across tool names ===\n";
$inputs = ['resource' => 'orders'];
$h1 = ToolCallHistory::hashInputs('foo', $inputs);
$h2 = ToolCallHistory::hashInputs('bar', $inputs);
assertTrue($h1 !== $h2, 'different tool name => different hash');

echo "=== countConsecutiveOccurrences after identical calls ===\n";
$history = new ToolCallHistory();
$hash = ToolCallHistory::hashInputs('list', ['x' => 1]);
$history->recordCall($hash, 'list', ['x' => 1]);
$history->recordCall($hash, 'list', ['x' => 1]);
$history->recordCall($hash, 'list', ['x' => 1]);
assertEquals(3, $history->countConsecutiveOccurrences($hash), '3 identical calls => count 3');

echo "=== countConsecutiveOccurrences reset by intercalated different call ===\n";
$history = new ToolCallHistory();
$hashA = ToolCallHistory::hashInputs('list', ['x' => 1]);
$hashB = ToolCallHistory::hashInputs('get', ['id' => 2]);
$history->recordCall($hashA, 'list', ['x' => 1]);
$history->recordCall($hashA, 'list', ['x' => 1]);
$history->recordCall($hashB, 'get', ['id' => 2]);
$history->recordCall($hashA, 'list', ['x' => 1]);
assertEquals(1, $history->countConsecutiveOccurrences($hashA), 'A after intercalated B => count 1');
assertEquals(0, $history->countConsecutiveOccurrences($hashB), 'B no longer at tail => count 0');

echo "=== getLastResultFor returns most recent result ===\n";
$history = new ToolCallHistory();
$hash = ToolCallHistory::hashInputs('list', ['x' => 1]);
$history->recordCall($hash, 'list', ['x' => 1]);
$history->recordResult($hash, 'first');
$history->recordCall($hash, 'list', ['x' => 1]);
$history->recordResult($hash, 'second');
assertEquals('second', $history->getLastResultFor($hash), 'getLastResultFor returns most recent');

echo "=== getLastResultFor returns null when no result recorded ===\n";
$history = new ToolCallHistory();
$hash = ToolCallHistory::hashInputs('list', ['x' => 1]);
$history->recordCall($hash, 'list', ['x' => 1]);
assertEquals(null, $history->getLastResultFor($hash), 'getLastResultFor null when result not yet set');

echo "\n=== getLastResultFor on unknown hash ===\n";
$history = new ToolCallHistory();
$unknownHash = ToolCallHistory::hashInputs('never_called', ['x' => 999]);
assertEquals(null, $history->getLastResultFor($unknownHash), 'getLastResultFor returns null for hash that was never recorded');

echo "\n=== countConsecutiveOccurrences on empty history ===\n";
$history = new ToolCallHistory();
$someHash = ToolCallHistory::hashInputs('list', ['x' => 1]);
assertEquals(0, $history->countConsecutiveOccurrences($someHash), 'count on empty history returns 0');

echo "\n=== Summary ===\n";
if ($failures > 0) { echo "$failures failure(s)\n"; exit(1); }
echo "All assertions passed.\n";
