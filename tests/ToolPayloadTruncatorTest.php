<?php
// tests/ToolPayloadTruncatorTest.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Chat\ToolPayloadTruncator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

$failures = 0;

function assertEq($expected, $actual, string $msg): void {
    global $failures;
    if ($expected !== $actual) {
        echo "  FAIL  {$msg}\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n";
        $failures++;
    } else {
        echo "  OK    {$msg}\n";
    }
}

function assertContains(string $needle, string $haystack, string $msg): void {
    global $failures;
    if (!str_contains($haystack, $needle)) {
        echo "  FAIL  {$msg}\n    expected to find: {$needle}\n    in: " . substr($haystack, 0, 200) . "\n";
        $failures++;
    } else {
        echo "  OK    {$msg}\n";
    }
}

function makeTool(string $name, array $inputs = [], string $result = ''): Tool {
    $tool = Tool::make($name, "Test tool: {$name}");
    $tool->setCallId('call_' . substr(md5($name . microtime()), 0, 8));
    $tool->setInputs($inputs);
    if ($result !== '') {
        $tool->setResult($result);
    }
    return $tool;
}

// Default threshold: 8000 chars = 2000 tokens.
$t = new ToolPayloadTruncator();

echo "=== isLatestPair=true never truncates ===\n";
$tool = makeTool('dolibarr_files_create', ['filename' => 'x', 'format' => 'csv', 'content' => str_repeat('A', 50000)]);
$msg = new ToolCallMessage(null, [$tool]);
$result = $t->truncateForPersistence($msg, true);
assertEq(50000, strlen($result->getTools()[0]->getInputs()['content']), 'latest pair untouched');

echo "\n=== UserMessage / AssistantMessage are pass-through ===\n";
$u = new UserMessage('hi');
$a = new AssistantMessage('hello');
assertEq($u, $t->truncateForPersistence($u, false), 'UserMessage untouched');
assertEq($a, $t->truncateForPersistence($a, false), 'AssistantMessage untouched');

echo "\n=== dolibarr_files_create truncates only content, keeps filename + format ===\n";
$tool = makeTool('dolibarr_files_create', ['filename' => 'rapport', 'format' => 'csv', 'content' => str_repeat('B', 50000)]);
$msg = new ToolCallMessage(null, [$tool]);
$result = $t->truncateForPersistence($msg, false);
$inputs = $result->getTools()[0]->getInputs();
assertEq('rapport', $inputs['filename'], 'filename preserved');
assertEq('csv', $inputs['format'], 'format preserved');
assertContains('[elided', $inputs['content'], 'content elided');
assertContains('50000 bytes written to disk', $inputs['content'], 'placeholder mentions size');
assertContains('download_url', $inputs['content'], 'placeholder mentions download_url');

echo "\n=== Generic tool truncates any large string input ===\n";
$tool = makeTool('mysql_select_query', ['query' => 'SELECT ' . str_repeat('x', 20000)]);
$msg = new ToolCallMessage(null, [$tool]);
$result = $t->truncateForPersistence($msg, false);
$inputs = $result->getTools()[0]->getInputs();
assertContains('[elided', $inputs['query'], 'query elided');
assertContains('chars, original tool arg truncated', $inputs['query'], 'generic placeholder applied');

echo "\n=== Small inputs are not touched ===\n";
$tool = makeTool('dolibarr_get', ['resource' => 'thirdparties', 'id' => 70]);
$msg = new ToolCallMessage(null, [$tool]);
$result = $t->truncateForPersistence($msg, false);
$inputs = $result->getTools()[0]->getInputs();
assertEq('thirdparties', $inputs['resource'], 'resource untouched');
assertEq(70, $inputs['id'], 'id untouched');

echo "\n=== ToolResultMessage with large result is truncated ===\n";
$bigResult = str_repeat('{"row":1,"data":"', 2000) . str_repeat('y', 1000);
$tool = makeTool('dolibarr_list', ['resource' => 'invoices', 'limit' => 100], $bigResult);
$msg = new ToolResultMessage([$tool]);
$result = $t->truncateForPersistence($msg, false);
$out = $result->getTools()[0]->getResult();
assertContains('[elided', $out, 'result elided');
assertContains('Tool: dolibarr_list', $out, 'placeholder mentions tool name');
assertContains('"resource":"invoices"', $out, 'placeholder mentions inputs');

echo "\n=== ToolResultMessage with small result is not truncated ===\n";
$tool = makeTool('dolibarr_get', ['resource' => 'thirdparties', 'id' => 70], '{"id":70,"name":"Acme"}');
$msg = new ToolResultMessage([$tool]);
$result = $t->truncateForPersistence($msg, false);
assertEq('{"id":70,"name":"Acme"}', $result->getTools()[0]->getResult(), 'small result untouched');

echo "\n=== Idempotent: re-truncating a truncated message is a no-op ===\n";
$tool = makeTool('dolibarr_files_create', ['filename' => 'x', 'format' => 'csv', 'content' => str_repeat('Z', 50000)]);
$msg = new ToolCallMessage(null, [$tool]);
$once = $t->truncateForPersistence($msg, false);
$contentAfterFirst = $once->getTools()[0]->getInputs()['content'];
$twice = $t->truncateForPersistence($once, false);
assertEq($contentAfterFirst, $twice->getTools()[0]->getInputs()['content'], 'second pass produces identical output');

echo "\n=== maxPayloadChars=0 bypasses all truncation ===\n";
$disabled = new ToolPayloadTruncator(0);
$tool = makeTool('dolibarr_files_create', ['filename' => 'x', 'format' => 'csv', 'content' => str_repeat('W', 50000)]);
$msg = new ToolCallMessage(null, [$tool]);
$result = $disabled->truncateForPersistence($msg, false);
assertEq(50000, strlen($result->getTools()[0]->getInputs()['content']), 'maxPayloadChars=0 is a bypass');

echo "\n=== Multi-tool message: only oversized tools mutated ===\n";
$big = makeTool('mysql_select_query', ['query' => str_repeat('Q', 20000)]);
$small = makeTool('dolibarr_get', ['resource' => 'thirdparties', 'id' => 70]);
$msg = new ToolCallMessage(null, [$big, $small]);
$result = $t->truncateForPersistence($msg, false);
$tools = $result->getTools();
assertContains('[elided', $tools[0]->getInputs()['query'], 'big tool elided');
assertEq('thirdparties', $tools[1]->getInputs()['resource'], 'small tool untouched');

echo "\n=== Non-string values preserved verbatim ===\n";
$tool = makeTool('some_tool', [
    'count' => 50,
    'enabled' => true,
    'options' => ['a' => 1, 'b' => 2],
    'big_string' => str_repeat('X', 20000),
]);
$msg = new ToolCallMessage(null, [$tool]);
$result = $t->truncateForPersistence($msg, false);
$inputs = $result->getTools()[0]->getInputs();
assertEq(50, $inputs['count'], 'int preserved');
assertEq(true, $inputs['enabled'], 'bool preserved');
assertEq(['a' => 1, 'b' => 2], $inputs['options'], 'array preserved');
assertContains('[elided', $inputs['big_string'], 'string elided');

echo "\n=== Below-500-chars threshold is clamped to 500 ===\n";
$clamped = new ToolPayloadTruncator(100);
$tool = makeTool('foo', ['arg' => str_repeat('A', 400)]); // 400 < 500 clamp, should NOT trigger
$msg = new ToolCallMessage(null, [$tool]);
$result = $clamped->truncateForPersistence($msg, false);
assertEq(400, strlen($result->getTools()[0]->getInputs()['arg']), 'below clamp not truncated');
$tool2 = makeTool('foo', ['arg' => str_repeat('A', 600)]); // 600 > 500 clamp, truncated
$msg2 = new ToolCallMessage(null, [$tool2]);
$result2 = $clamped->truncateForPersistence($msg2, false);
assertContains('[elided', $result2->getTools()[0]->getInputs()['arg'], 'above clamp truncated');

echo "\n=== approxTokens sanity ===\n";
assertEq(0, ToolPayloadTruncator::approxTokens(''), 'empty string is 0 tokens');
assertEq(2000, ToolPayloadTruncator::approxTokens(str_repeat('x', 8000)), '8000 chars is ~2000 tokens');
assertEq(1, ToolPayloadTruncator::approxTokens('xxxx'), '4 chars is ~1 token');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
