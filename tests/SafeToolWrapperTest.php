<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Tools\SafeToolWrapper;
use Dalfred\Tools\ToolCallHistory;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

$failures = 0;
function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertEquals($expected, $actual, string $msg): void {
    global $failures;
    if ($expected !== $actual) { echo "  FAIL  $msg (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertContains(string $needle, string $haystack, string $msg): void {
    global $failures;
    if (strpos($haystack, $needle) === false) { echo "  FAIL  $msg (needle " . var_export($needle, true) . " not in " . var_export(substr($haystack, 0, 200), true) . ")\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

/**
 * Build a NeuronAI Tool whose callback is a configurable closure.
 * The tool declares one optional property `x` so that setInputs(['x' => …])
 * actually flows through Tool::execute()'s parameter-building loop and
 * reaches the callable via named-arg variadic capture — matching how
 * SafeToolWrapper computes its hash in production.
 */
function buildToolWith(string $name, callable $callable): Tool
{
    return Tool::make($name, 'Test tool ' . $name)
        ->addProperty(new ToolProperty('x', PropertyType::STRING, 'test arg', false))
        ->setCallable($callable);
}

echo "=== Happy path: returns string, history records call+result ===\n";
$history = new ToolCallHistory();
$inner = buildToolWith('echo_tool', fn (...$args) => 'ok');
$wrapped = SafeToolWrapper::wrap($inner, $history);
$wrapped->setInputs([]);
$wrapped->execute();
assertEquals('ok', $wrapped->getResult(), 'happy path returns inner result');

echo "\n=== Null return becomes structured ToolReturnedNull payload ===\n";
$history = new ToolCallHistory();
$inner = buildToolWith('null_tool', fn (...$args) => null);
$wrapped = SafeToolWrapper::wrap($inner, $history);
$wrapped->setInputs([]);
$wrapped->execute();
$result = $wrapped->getResult();
assertContains('"ToolReturnedNull"', $result, 'null return wrapped with code ToolReturnedNull');
assertContains('"error":true', $result, 'null return marked as error');

echo "\n=== Throwable (RuntimeException) caught and wrapped ===\n";
$history = new ToolCallHistory();
$inner = buildToolWith('boom', function (...$args): string {
    throw new \RuntimeException('kaboom');
});
$wrapped = SafeToolWrapper::wrap($inner, $history);
$wrapped->setInputs([]);
$wrapped->execute();
$result = $wrapped->getResult();
assertContains('"ToolExecutionFailed"', $result, 'RuntimeException wrapped with code ToolExecutionFailed');
assertContains('kaboom', $result, 'exception message included');

echo "\n=== TypeError (extends Error) also caught (not just Exception) ===\n";
$history = new ToolCallHistory();
$inner = buildToolWith('typeerror', function (...$args): string {
    throw new \TypeError('type mismatch');
});
$wrapped = SafeToolWrapper::wrap($inner, $history);
$wrapped->setInputs([]);
$wrapped->execute();
$result = $wrapped->getResult();
assertContains('"ToolExecutionFailed"', $result, 'TypeError caught (catch \\Throwable not \\Exception)');
assertContains('type mismatch', $result, 'TypeError message included');

echo "\n=== Fourth identical call intercepted without invoking inner ===\n";
$history = new ToolCallHistory();
$invocationCount = 0;
$inner = buildToolWith('counter', function (...$args) use (&$invocationCount): string {
    $invocationCount++;
    return 'real_result_' . $invocationCount;
});

// A fresh SafeToolWrapper per call is required because setInputs() runs
// the loop-detection guard at construction-time check. Reusing one wrapper
// would not re-evaluate the guard against the updated $history (the guard
// fires once during the first setInputs); each new call must enter a new
// wrapper that re-reads countConsecutiveOccurrences. The shared $history
// is what propagates the count across these per-call wrappers.
$wrapped1 = SafeToolWrapper::wrap($inner, $history);
$wrapped1->setInputs(['x' => 1]);
$wrapped1->execute();
assertEquals('real_result_1', $wrapped1->getResult(), '1st call invokes inner');
assertEquals(1, $invocationCount, 'invocation count after 1st call');

$wrapped2 = SafeToolWrapper::wrap($inner, $history);
$wrapped2->setInputs(['x' => 1]);
$wrapped2->execute();
assertEquals('real_result_2', $wrapped2->getResult(), '2nd identical call still invokes inner');
assertEquals(2, $invocationCount, 'invocation count after 2nd call');

$wrapped3 = SafeToolWrapper::wrap($inner, $history);
$wrapped3->setInputs(['x' => 1]);
$wrapped3->execute();
assertEquals('real_result_3', $wrapped3->getResult(), '3rd identical call still invokes inner (count becomes 3)');
assertEquals(3, $invocationCount, 'invocation count after 3rd call');

// 4th call: count is already 3 BEFORE this call, threshold is reached, intercept.
$wrapped4 = SafeToolWrapper::wrap($inner, $history);
$wrapped4->setInputs(['x' => 1]);
$wrapped4->execute();
$result = $wrapped4->getResult();
assertContains('"RepeatedToolCallDetected"', $result, '4th identical call intercepted');
assertEquals(3, $invocationCount, 'inner NOT invoked on 4th call');

echo "\n=== Different inputs after 2 identical resets counter ===\n";
$history = new ToolCallHistory();
$invocationCount = 0;
$inner = buildToolWith('reset_test', function (...$args) use (&$invocationCount): string {
    $invocationCount++;
    return 'r' . $invocationCount;
});

$wrappedA1 = SafeToolWrapper::wrap($inner, $history);
$wrappedA1->setInputs(['x' => 1]);
$wrappedA1->execute();

$wrappedA2 = SafeToolWrapper::wrap($inner, $history);
$wrappedA2->setInputs(['x' => 1]);
$wrappedA2->execute();

$wrappedB = SafeToolWrapper::wrap($inner, $history);
$wrappedB->setInputs(['x' => 2]);
$wrappedB->execute();

// Now A again — A's consecutive counter was reset by B, so this should pass.
$wrappedA3 = SafeToolWrapper::wrap($inner, $history);
$wrappedA3->setInputs(['x' => 1]);
$wrappedA3->execute();
assertEquals('r4', $wrappedA3->getResult(), 'A invoked again after intercalated B (no interception)');
assertEquals(4, $invocationCount, 'inner invoked 4 times total');

echo "\n=== wrap() is idempotent ===\n";
$history = new ToolCallHistory();
$inner = buildToolWith('idem', fn (...$args) => 'x');
$w1 = SafeToolWrapper::wrap($inner, $history);
$w2 = SafeToolWrapper::wrap($w1, $history);
assertTrue($w1 === $w2, 'wrap(wrap(t)) returns the same instance');

echo "\n=== Wrapper exposes inner tool's name and description ===\n";
$inner = buildToolWith('public_name', fn (...$args) => '')->setDescription('public desc');
$history = new ToolCallHistory();
$wrapped = SafeToolWrapper::wrap($inner, $history);
assertEquals('public_name', $wrapped->getName(), 'name mirrored from inner');
assertEquals('public desc', $wrapped->getDescription(), 'description mirrored from inner');

echo "\n=== Summary ===\n";
if ($failures > 0) { echo "$failures failure(s)\n"; exit(1); }
echo "All assertions passed.\n";
