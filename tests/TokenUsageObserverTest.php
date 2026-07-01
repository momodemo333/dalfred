<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', '');
}
if (!function_exists('dol_syslog')) {
    function dol_syslog(string $msg, int $level = 0): void { /* swallow */ }
}
if (!function_exists('getDolGlobalInt')) {
    function getDolGlobalInt(string $name, int $default = 0): int { return $default; }
}
if (!defined('LOG_WARNING')) define('LOG_WARNING', 4);
if (!defined('LOG_ERR')) define('LOG_ERR', 3);

use Dalfred\Observer\TokenUsageObserver;
use Dalfred\Repository\TokenUsageRepository;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;

$failures = 0;

function assertEq($expected, $actual, string $message): void {
    global $failures;
    if ($expected !== $actual) {
        echo "  FAIL  {$message}\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n";
        $failures++;
    } else {
        echo "  OK    {$message}\n";
    }
}

// Fake repository capturing every insert() call without touching PDO.
final class FakeRepo extends TokenUsageRepository
{
    /** @var array<int, array<string,mixed>> */
    public array $inserts = [];
    public bool $shouldThrow = false;

    public function __construct() { /* skip parent constructor on purpose */ }

    public function insert(array $data): void
    {
        if ($this->shouldThrow) {
            throw new \RuntimeException('simulated DB failure');
        }
        $this->inserts[] = $data;
    }
}

echo "=== Insert on InferenceStop with Usage ===\n";
$repo = new FakeRepo();
$obs = new TokenUsageObserver($repo, userId: 5, entityId: 1, threadId: 'th-1', model: 'gpt-4o', provider: 'openai');

$obs->onEvent('inference-start', (object) [], new InferenceStart(new UserMessage('hi')));

$resp = new AssistantMessage('hello');
$resp->setUsage(new Usage(1000, 500));
$obs->onEvent('inference-stop', (object) [], new InferenceStop(false, $resp));

assertEq(1, count($repo->inserts), 'one insert on inference-stop');
$row = $repo->inserts[0];
assertEq(1000, $row['input_tokens'], 'input tokens captured');
assertEq(500,  $row['output_tokens'], 'output tokens captured');
assertEq('gpt-4o', $row['model'], 'model captured');
assertEq('openai', $row['provider'], 'provider captured');
assertEq('th-1', $row['thread_id'], 'thread captured');
assertEq(5, $row['fk_user'], 'user captured');
assertEq(true, $row['duration_ms'] >= 0, 'duration_ms is non-negative');

echo "\n=== Null Usage results in zeros (graceful) ===\n";
$repo = new FakeRepo();
$obs = new TokenUsageObserver($repo, 5, 1, 'th-2', 'ollama-llama', 'ollama');
$resp = new AssistantMessage('hi'); // no usage set
$obs->onEvent('inference-stop', (object) [], new InferenceStop(false, $resp));

assertEq(1, count($repo->inserts), 'still inserts');
assertEq(0, $repo->inserts[0]['input_tokens'], 'input is 0 when usage missing');
assertEq(0, $repo->inserts[0]['output_tokens'], 'output is 0 when usage missing');

echo "\n=== Other events ignored ===\n";
$repo = new FakeRepo();
$obs = new TokenUsageObserver($repo, 5, 1, 'th-3', 'gpt-4o', 'openai');
$obs->onEvent('something-else', (object) [], (object) ['foo' => 'bar']);
assertEq(0, count($repo->inserts), 'no insert for unrelated event');

echo "\n=== Repository exception swallowed (never breaks inference) ===\n";
$repo = new FakeRepo();
$repo->shouldThrow = true;
$obs = new TokenUsageObserver($repo, 5, 1, 'th-4', 'gpt-4o', 'openai');
$resp = new AssistantMessage('hi');
$resp->setUsage(new Usage(100, 50));
$caught = false;
try {
    $obs->onEvent('inference-stop', (object) [], new InferenceStop(false, $resp));
} catch (\Throwable $e) {
    $caught = true;
}
assertEq(false, $caught, 'observer swallows DB exception');

echo "\n=== setThreadId() updates context for subsequent events ===\n";
$repo = new FakeRepo();
$obs = new TokenUsageObserver($repo, 5, 1, null, 'gpt-4o', 'openai');
$obs->setThreadId('th-late');
$resp = new AssistantMessage('hi');
$resp->setUsage(new Usage(10, 5));
$obs->onEvent('inference-stop', (object) [], new InferenceStop(false, $resp));
assertEq('th-late', $repo->inserts[0]['thread_id'], 'late-bound thread used');

echo "\n=== Self-disables after 3 errors, ignores subsequent events ===\n";
$repo = new FakeRepo();
$repo->shouldThrow = true;
$obs = new TokenUsageObserver($repo, 5, 1, 'th-disable', 'gpt-4o', 'openai');
$resp = new AssistantMessage('hi');
$resp->setUsage(new Usage(1, 1));
$stop = new InferenceStop(false, $resp);

// First 3 inference-stop calls all throw and get swallowed; on the 3rd
// the observer self-disables.
$obs->onEvent('inference-stop', (object) [], $stop);  // err 1
$obs->onEvent('inference-stop', (object) [], $stop);  // err 2
$obs->onEvent('inference-stop', (object) [], $stop);  // err 3 → disabled

// Repo recovers, but the observer must ignore all further events.
$repo->shouldThrow = false;
$obs->onEvent('inference-stop', (object) [], $stop);
$obs->onEvent('inference-stop', (object) [], $stop);

assertEq(0, count($repo->inserts), 'disabled observer ignores events even after repo recovers');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
