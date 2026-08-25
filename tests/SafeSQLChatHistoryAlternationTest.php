<?php

/**
 * Regression tests for SafeSQLChatHistory::load() alternation repair.
 *
 * Reproduces the production incident where a thread became permanently
 * unusable with:
 *   NeuronAI\Exceptions\ChatHistoryException
 *   "Invalid message sequence at position N: expected role assistant, got user"
 *
 * The repair pass must reproduce exactly the state machine of
 * HistoryTrimmer::validateAlternation(), otherwise a corrupted history is
 * loaded as-is and every subsequent addMessage() throws.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Chat\SafeSQLChatHistory;
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

/** Asserts that adding a new user message on a reloaded thread does not throw. */
function assertResumable(\PDO $pdo, string $threadId, string $msg): void {
    global $failures;
    try {
        $history = new SafeSQLChatHistory($threadId, $pdo, 'chat_history', 100000);
        $history->addMessage(new UserMessage('Follow-up question'));
        echo "  OK    {$msg}\n";
    } catch (\Throwable $e) {
        echo "  FAIL  {$msg}\n    threw: " . $e::class . ': ' . $e->getMessage() . "\n";
        $failures++;
    }
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

function makeTool(string $name, string $result = 'ok'): Tool {
    $tool = Tool::make($name, "Test tool: {$name}");
    $tool->setCallId('call_' . substr(md5($name . uniqid()), 0, 8));
    $tool->setInputs(['filename' => 'report', 'format' => 'csv']);
    $tool->setResult($result);
    return $tool;
}

function loadRaw(\PDO $pdo, string $threadId): array {
    $stmt = $pdo->prepare('SELECT messages FROM chat_history WHERE thread_id = ?');
    $stmt->execute([$threadId]);
    return json_decode($stmt->fetch(\PDO::FETCH_ASSOC)['messages'], true);
}

/** Rewrites the raw JSON of a thread, dropping the last $n messages (simulates a crash mid-turn). */
function dropLastMessages(\PDO $pdo, string $threadId, int $n): void {
    $raw = loadRaw($pdo, $threadId);
    $raw = array_slice($raw, 0, count($raw) - $n);
    $upd = $pdo->prepare('UPDATE chat_history SET messages = ? WHERE thread_id = ?');
    $upd->execute([json_encode($raw), $threadId]);
}

// ---------------------------------------------------------------------------
echo "=== Scenario 1: crash after ToolResult, assistant answer never persisted ===\n";
// This is the production case: [User, ToolCall, ToolResult] then the user sends
// a new message. validateAlternation() expects ASSISTANT after a ToolResult.
$tid = 'thread_crash_after_toolresult';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('Generate a CSV'));
$h->addMessage(new ToolCallMessage(null, [makeTool('dolibarr_files_create')]));
$h->addMessage(new ToolResultMessage([makeTool('dolibarr_files_create', '{"success":true}')]));
$h->addMessage(new AssistantMessage('Here is your file.'));
dropLastMessages($pdo, $tid, 1); // the assistant answer is lost (PHP timeout)
assertResumable($pdo, $tid, 'thread ending on ToolResult stays usable');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 2: crash right after the user message (no tools involved) ===\n";
// History ends on [.., Assistant, User]; the next user message makes it user/user.
$tid = 'thread_crash_after_user';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('Hello'));
$h->addMessage(new AssistantMessage('Hi!'));
$h->addMessage(new UserMessage('Give me the invoice list'));
$h->addMessage(new AssistantMessage('Here it is.'));
dropLastMessages($pdo, $tid, 1);
assertResumable($pdo, $tid, 'thread ending on an orphan user message stays usable');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 3: two consecutive user messages in the middle ===\n";
// Already covered before this fix - must not regress.
$tid = 'thread_double_user_middle';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('One'));
$h->addMessage(new AssistantMessage('Answer one'));
$h->addMessage(new UserMessage('Two'));
$h->addMessage(new AssistantMessage('Answer two'));
$h->addMessage(new UserMessage('Three'));
$h->addMessage(new AssistantMessage('Answer three'));
$stmt = $pdo->prepare('SELECT messages FROM chat_history WHERE thread_id = ?');
$stmt->execute([$tid]);
$raw = json_decode($stmt->fetch(\PDO::FETCH_ASSOC)['messages'], true);
unset($raw[3]); // remove "Answer two" -> [User, Assistant, User, User, Assistant]
$upd = $pdo->prepare('UPDATE chat_history SET messages = ? WHERE thread_id = ?');
$upd->execute([json_encode(array_values($raw)), $tid]);
assertResumable($pdo, $tid, 'thread with two consecutive user messages stays usable');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 4: history starting with an assistant message ===\n";
// validateAlternation() starts with expectingUser = true, so a leading
// assistant message is invalid too.
$tid = 'thread_leading_assistant';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('First'));
$h->addMessage(new AssistantMessage('Reply'));
$stmt->execute([$tid]);
$raw = json_decode($stmt->fetch(\PDO::FETCH_ASSOC)['messages'], true);
array_shift($raw); // drop the leading user message
$upd->execute([json_encode(array_values($raw)), $tid]);
assertResumable($pdo, $tid, 'thread starting with an assistant message stays usable');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 5: a healthy thread is left untouched ===\n";
$tid = 'thread_healthy';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('Generate a CSV'));
$h->addMessage(new ToolCallMessage(null, [makeTool('dolibarr_files_create')]));
$h->addMessage(new ToolResultMessage([makeTool('dolibarr_files_create', '{"success":true}')]));
$h->addMessage(new AssistantMessage('Here is your file.'));
$reloaded = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
assertEq(4, count($reloaded->getMessages()), 'healthy thread keeps its 4 messages (no placeholder injected)');
assertResumable($pdo, $tid, 'healthy thread stays usable');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 6: a turn still running (async mode) is not touched by load() ===\n";
// In async mode the user message is persisted, then the LLM runs in the
// background. Any other request loading the thread meanwhile (polling, second
// tab) must NOT inject a placeholder, which would corrupt the live turn.
$tid = 'thread_async_in_flight';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('Hello'));
$h->addMessage(new AssistantMessage('Hi!'));
$h->addMessage(new UserMessage('Question still being processed'));
$before = json_encode(loadRaw($pdo, $tid));
$concurrent = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
assertEq(3, count($concurrent->getMessages()), 'in-flight turn keeps its 3 messages');
assertEq($before, json_encode(loadRaw($pdo, $tid)), 'in-flight turn is left untouched in DB');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 7: repair is idempotent ===\n";
// A repaired thread must not gain a new placeholder on every single turn.
$tid = 'thread_idempotent';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('Generate a CSV'));
$h->addMessage(new ToolCallMessage(null, [makeTool('dolibarr_files_create')]));
$h->addMessage(new ToolResultMessage([makeTool('dolibarr_files_create', '{"success":true}')]));
$h->addMessage(new AssistantMessage('Here is your file.'));
dropLastMessages($pdo, $tid, 1);

$h1 = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h1->addMessage(new UserMessage('First follow-up'));       // repairs: +1 placeholder
$h1->addMessage(new AssistantMessage('First answer'));
assertEq(6, count($h1->getMessages()), 'one placeholder inserted on the repairing turn');

$h2 = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h2->addMessage(new UserMessage('Second follow-up'));      // must repair nothing
$h2->addMessage(new AssistantMessage('Second answer'));
assertEq(8, count($h2->getMessages()), 'later turns add no further placeholder');

// ---------------------------------------------------------------------------
echo "\n=== Scenario 8: placeholder texts are injectable (i18n) ===\n";
$tid = 'thread_i18n';
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000);
$h->addMessage(new UserMessage('Hello'));
$h->addMessage(new AssistantMessage('Hi!'));
$h->addMessage(new UserMessage('Lost question'));
$h->addMessage(new AssistantMessage('Lost answer'));
dropLastMessages($pdo, $tid, 1);
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000, null, '[ANSWER LOST]', '[QUESTION LOST]');
$h->addMessage(new UserMessage('Follow-up'));
$messages = $h->getMessages();
assertEq('[ANSWER LOST]', $messages[3]->getContent(), 'injected placeholder text is used');

echo "\n";
if ($failures > 0) {
    echo "{$failures} TEST(S) FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
