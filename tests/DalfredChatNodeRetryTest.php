<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Agent\Nodes\DalfredChatNode;
use Dalfred\Observer\ActivityLogObserver;
use Dalfred\Service\EmptyResponseGuard;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

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

/**
 * Minimal test observer that records calls in memory instead of writing to
 * the Dalfred DB. Mirrors the public interface of ActivityLogObserver that
 * DalfredChatNode actually calls.
 */
final class InMemoryActivityLogObserver extends ActivityLogObserver
{
    public array $emptyResponseEvents = [];

    public function __construct()
    {
        // Skip parent constructor — no DB needed.
    }

    public function logEmptyProviderResponse(
        string $provider,
        string $model,
        ?string $threadId,
        array $stopReasons
    ): void {
        $this->emptyResponseEvents[] = [
            'provider' => $provider,
            'model' => $model,
            'thread_id' => $threadId,
            'stop_reasons' => $stopReasons,
        ];
    }
}

/**
 * Subclass DalfredChatNode just to expose the protected `inference` method
 * for direct testing. No behavioral override.
 */
final class TestableDalfredChatNode extends DalfredChatNode
{
    public function inferenceForTest(AIInferenceEvent $event, array $messages): \NeuronAI\Chat\Messages\Message
    {
        return $this->inference($event, $messages);
    }
}

// Helpers to build the AIInferenceEvent + messages that ChatNode::inference expects.
function buildEvent(): AIInferenceEvent {
    // Construct with a minimal instructions string; no tools needed for these
    // tests since FakeAIProvider ignores them.
    return new AIInferenceEvent('test system prompt', []);
}

function buildMessages(): array {
    // Empty array is fine — FakeAIProvider returns its queued responses
    // without inspecting the message stack.
    return [];
}

echo "=== Scenario A: empty first, real second — retry succeeds ===\n";
$provider = new FakeAIProvider(
    (new AssistantMessage(null))->setStopReason('STOP'),
    new AssistantMessage('Réponse réelle')
);
$node = new TestableDalfredChatNode($provider);
$result = $node->inferenceForTest(buildEvent(), buildMessages());
assertEq('Réponse réelle', $result->getContent(), 'retry returns the real second response');
$provider->assertCallCount(2);
echo "  OK    provider called exactly twice\n";

echo "\n=== Scenario B: empty first, empty second — fallback shown + activity row written ===\n";
$obs = new InMemoryActivityLogObserver();
$provider = new FakeAIProvider(
    (new AssistantMessage(null))->setStopReason('STOP'),
    (new AssistantMessage(null))->setStopReason('STOP')
);
$ctx = ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'thread_id' => 'thread_test_B'];
$node = new TestableDalfredChatNode($provider, null, $obs, $ctx);
$result = $node->inferenceForTest(buildEvent(), buildMessages());
$resultContent = $result->getContent() ?? '';
assertContains("fournisseur d'IA", $resultContent, 'fallback message is shown after double-empty');
assertEq(1, count($obs->emptyResponseEvents), 'exactly one activity_log error row written');
$ev = $obs->emptyResponseEvents[0];
assertEq('gemini', $ev['provider'], 'context provider preserved');
assertEq('gemini-2.5-flash', $ev['model'], 'context model preserved');
assertEq('thread_test_B', $ev['thread_id'], 'context thread_id preserved');
assertEq(['STOP', 'STOP'], $ev['stop_reasons'], 'stop_reasons captured');
$provider->assertCallCount(2);
echo "  OK    provider called exactly twice; one error row recorded\n";

echo "\n=== Scenario C: real first response — no retry, no log ===\n";
$obs = new InMemoryActivityLogObserver();
$provider = new FakeAIProvider(new AssistantMessage('Tout va bien'));
$node = new TestableDalfredChatNode($provider, null, $obs);
$result = $node->inferenceForTest(buildEvent(), buildMessages());
assertEq('Tout va bien', $result->getContent(), 'real first response is returned as-is');
assertEq(0, count($obs->emptyResponseEvents), 'no activity log row when first response is OK');
$provider->assertCallCount(1);
echo "  OK    provider called exactly once; no error row\n";

echo "\n=== Scenario D: MAX_TOKENS-empty first — no retry, no log ===\n";
$obs = new InMemoryActivityLogObserver();
$provider = new FakeAIProvider(
    (new AssistantMessage(null))->setStopReason('MAX_TOKENS')
);
$node = new TestableDalfredChatNode($provider, null, $obs);
$result = $node->inferenceForTest(buildEvent(), buildMessages());
// We return the MAX_TOKENS-empty message as-is (legitimately empty)
assertEq(null, $result->getContent(), 'legitimate MAX_TOKENS empty is returned unchanged');
assertEq(0, count($obs->emptyResponseEvents), 'no log row on legitimately empty response');
$provider->assertCallCount(1);
echo "  OK    provider called exactly once; no retry attempted\n";

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
