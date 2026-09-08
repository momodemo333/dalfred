<?php

/**
 * Reproduces the production failure reported by a customer on 2026-09-04:
 * a thread opened months earlier answered
 *
 *   HTTP 400 ... Invalid JSON payload received.
 *   Unknown name "1" at 'contents[32].parts': Cannot find field.
 *
 * to every question, because a tool message persisted with a holed tools array
 * ({"1": {...}} instead of [{...}]) was re-sent on every turn.
 *
 * Run: php tests/ToolListNormalizerTest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Chat\SafeSQLChatHistory;
use Dalfred\Chat\ToolListNormalizer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\MessageMapper;
use NeuronAI\Tools\Tool;

$failures = 0;

function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "  OK    {$msg}\n";
    } else {
        echo "  FAIL  {$msg}\n";
        $failures++;
    }
}

function makeTool(string $name, string $result = 'ok'): Tool {
    $tool = Tool::make($name, "Test tool: {$name}");
    $tool->setCallId('call_' . substr(md5($name . uniqid()), 0, 8));
    $tool->setInputs(['ref' => 'FA2601-0001']);
    $tool->setResult($result);
    return $tool;
}

/** Builds a tool message whose tools array starts at key 1, as Gemini's array_filter leaves it. */
function holedResultMessage(): ToolResultMessage {
    $message = new ToolResultMessage([makeTool('dolibarr_list')]);
    $property = new \ReflectionProperty($message, 'tools');
    $property->setAccessible(true);
    $property->setValue($message, [1 => $property->getValue($message)[0]]);
    return $message;
}

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE chat_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id TEXT NOT NULL,
        messages TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now'))
    )"
);
$pdo->exec("CREATE UNIQUE INDEX uk_thread_id ON chat_history (thread_id)");

// ---------------------------------------------------------------------------
echo "=== Scenario 1: the bug itself — a holed tools array breaks the Gemini payload ===\n";
$broken = holedResultMessage();
$payload = (new MessageMapper())->map([$broken]);
$json = json_encode($payload[0]);
assertTrue(
    str_contains((string) $json, '"parts":{"1":'),
    'unrepaired: parts serialises as a JSON object, which Gemini rejects with 400'
);

ToolListNormalizer::normalize($broken);
$payload = (new MessageMapper())->map([$broken]);
$json = json_encode($payload[0]);
assertTrue(
    str_contains((string) $json, '"parts":[{'),
    'repaired: parts serialises as a JSON array'
);
assertTrue(
    count($broken->getTools()) === 1,
    'repaired: the tool itself is preserved, not dropped'
);

// ---------------------------------------------------------------------------
echo "\n=== Scenario 2: a healthy message is left strictly alone ===\n";
$healthy = new ToolResultMessage([makeTool('dolibarr_get')]);
assertTrue(
    ToolListNormalizer::normalize($healthy) === false,
    'a well-formed tools array reports no change'
);
assertTrue(
    ToolListNormalizer::normalize(new UserMessage('hello')) === false,
    'a non-tool message reports no change'
);

// ---------------------------------------------------------------------------
echo "\n=== Scenario 3: the customer case — corruption already persisted in the DB ===\n";
// A thread stored months ago with "tools":{"1":...}. Every load must heal it,
// and the healed version must be written back so it is fixed once and for all.
$tid = 'thread_holed_tools';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('List my invoices'));
$h->addMessage(new ToolCallMessage(null, [makeTool('dolibarr_list')]));
$h->addMessage(new ToolResultMessage([makeTool('dolibarr_list', '{"ok":true}')]));
$h->addMessage(new AssistantMessage('Here they are.'));

// Corrupt the stored JSON exactly the way the provider path did.
$stmt = $pdo->prepare('SELECT messages FROM chat_history WHERE thread_id = ?');
$stmt->execute([$tid]);
$raw = json_decode($stmt->fetch(\PDO::FETCH_ASSOC)['messages'], true);
foreach ($raw as $i => $msg) {
    if (($msg['type'] ?? null) === 'tool_call_result') {
        $raw[$i]['tools'] = [1 => $msg['tools'][0]];
    }
}
$corrupted = json_encode($raw);
assertTrue(
    str_contains((string) $corrupted, '"tools":{"1":'),
    'fixture: the stored history really is corrupted'
);
$pdo->prepare('UPDATE chat_history SET messages = ? WHERE thread_id = ?')
    ->execute([$corrupted, $tid]);

// Reload: the repair must happen and be persisted.
$reloaded = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$mapped = json_encode((new MessageMapper())->map($reloaded->getMessages()));
assertTrue(
    !str_contains((string) $mapped, '"parts":{"1":'),
    'after load: the whole history maps to a Gemini-valid payload'
);

$stmt->execute([$tid]);
$after = $stmt->fetch(\PDO::FETCH_ASSOC)['messages'];
assertTrue(
    !str_contains((string) $after, '"tools":{"1":'),
    'after load: the healed history was written back to the database'
);

// And the thread is usable again.
try {
    $reloaded->addMessage(new UserMessage('And the paid ones?'));
    echo "  OK    the previously dead thread accepts a new message\n";
} catch (\Throwable $e) {
    echo "  FAIL  the previously dead thread accepts a new message\n    threw: "
        . $e::class . ': ' . $e->getMessage() . "\n";
    $failures++;
}

// ---------------------------------------------------------------------------
echo "\n=== Scenario 4: nothing corrupt can be written, whatever the route ===\n";
$tid2 = 'thread_incoming_holed';
$h2 = new SafeSQLChatHistory($tid2, $pdo, 'chat_history', 100000);
$h2->addMessage(new UserMessage('List my invoices'));
$h2->addMessage(new ToolCallMessage(null, [makeTool('dolibarr_list')]));
$h2->addMessage(holedResultMessage());

$stmt->execute([$tid2]);
$stored = $stmt->fetch(\PDO::FETCH_ASSOC)['messages'];
assertTrue(
    !str_contains((string) $stored, '"tools":{"1":'),
    'a holed message handed to addMessage() is normalised before persistence'
);

echo "\n";
if ($failures > 0) {
    echo "{$failures} failure(s)\n";
    exit(1);
}
echo "All assertions passed.\n";
