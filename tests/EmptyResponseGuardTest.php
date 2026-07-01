<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\EmptyResponseGuard;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
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

$guard = new EmptyResponseGuard();

echo "=== ToolCallMessage is never considered empty ===\n";
$tool = Tool::make('foo', 'description');
$msg = new ToolCallMessage(null, [$tool]);
assertEq(false, $guard->shouldRetry($msg), 'ToolCallMessage with null content does not trigger retry');

echo "\n=== null content + STOP triggers retry ===\n";
$msg = (new AssistantMessage(null))->setStopReason('STOP');
assertEq(true, $guard->shouldRetry($msg), 'null content + STOP triggers retry');

echo "\n=== empty-string content + STOP triggers retry ===\n";
$msg = (new AssistantMessage(''))->setStopReason('STOP');
assertEq(true, $guard->shouldRetry($msg), 'empty string + STOP triggers retry');

echo "\n=== whitespace-only content + STOP triggers retry ===\n";
$msg = (new AssistantMessage("   \n\t  "))->setStopReason('STOP');
assertEq(true, $guard->shouldRetry($msg), 'whitespace-only + STOP triggers retry');

echo "\n=== real content + STOP does not trigger retry ===\n";
$msg = (new AssistantMessage('Bonjour'))->setStopReason('STOP');
assertEq(false, $guard->shouldRetry($msg), 'real content does not trigger retry');

echo "\n=== null content + MAX_TOKENS does not trigger retry (legitimate empty) ===\n";
$msg = (new AssistantMessage(null))->setStopReason('MAX_TOKENS');
assertEq(false, $guard->shouldRetry($msg), 'MAX_TOKENS legitimate empty, no retry');

echo "\n=== null content + null stopReason triggers retry (assume STOP) ===\n";
$msg = new AssistantMessage(null);
assertEq(true, $guard->shouldRetry($msg), 'null stopReason on empty content = retry');

echo "\n=== null content + lowercase 'safety' does not trigger retry (case-insensitive) ===\n";
$msg = (new AssistantMessage(null))->setStopReason('safety');
assertEq(false, $guard->shouldRetry($msg), 'safety filter (lowercase) no retry');

echo "\n=== normalizeStopReason: uppercase + UNKNOWN ===\n";
$m1 = (new AssistantMessage('x'))->setStopReason('stop');
assertEq('STOP', EmptyResponseGuard::normalizeStopReason($m1), 'lowercase stop becomes STOP');
$m2 = new AssistantMessage('x');
assertEq('UNKNOWN', EmptyResponseGuard::normalizeStopReason($m2), 'null stopReason becomes UNKNOWN');
$m3 = (new AssistantMessage('x'))->setStopReason('MAX_TOKENS');
assertEq('MAX_TOKENS', EmptyResponseGuard::normalizeStopReason($m3), 'preserves uppercase as-is');

echo "\n=== buildFallbackMessage returns proper AssistantMessage ===\n";
$fallback = $guard->buildFallbackMessage();
assertEq(AssistantMessage::class, $fallback::class, 'fallback type is AssistantMessage');
$content = $fallback->getContent() ?? '';
assertContains("fournisseur d'IA", $content, 'fallback mentions fournisseur d\'IA neutrally');
assertContains("journal d'activité", $content, 'fallback mentions journal d\'activité');
assertContains('Reformule', $content, 'fallback gives an actionable Reformule instruction');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
