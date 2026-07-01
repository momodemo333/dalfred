<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Chat\SafeSQLChatHistory;
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

// SQLite in-memory PDO.
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
// Same schema NeuronAI v3 SQLChatHistory uses.
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

function makeTool(string $name, array $inputs = [], string $result = ''): Tool {
    $tool = Tool::make($name, "Test tool: {$name}");
    $tool->setCallId('call_' . substr(md5($name . microtime()), 0, 8));
    $tool->setInputs($inputs);
    if ($result !== '') {
        $tool->setResult($result);
    }
    return $tool;
}

function loadRawRow(\PDO $pdo, string $threadId): array {
    $stmt = $pdo->prepare('SELECT messages FROM chat_history WHERE thread_id = ?');
    $stmt->execute([$threadId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return [];
    }
    return json_decode($row['messages'], true);
}

echo "=== Latest tool pair is persisted intact (one turn of grace) ===\n";
$tid = 'thread_test_grace';
$truncator = new ToolPayloadTruncator(8000);
$h = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000, $truncator);

$h->addMessage(new UserMessage('Generate a CSV'));
$callTool = makeTool('dolibarr_files_create', ['filename' => 'rep', 'format' => 'csv', 'content' => str_repeat('A', 50000)]);
$h->addMessage(new ToolCallMessage(null, [$callTool]));
$resultTool = makeTool('dolibarr_files_create', ['filename' => 'rep', 'format' => 'csv', 'content' => str_repeat('A', 50000)], '{"success":true,"filename":"rep.csv","size":50000,"download_url":"/custom/dalfred/download.php?f=rep.csv","agent_hint":"..."}');
$h->addMessage(new ToolResultMessage([$resultTool]));

$raw = loadRawRow($pdo, $tid);
assertEq(50000, strlen($raw[1]['tools'][0]['inputs']['content']), 'latest ToolCall content kept intact in DB');
assertContains('"success":true', $raw[2]['tools'][0]['result'], 'latest ToolResult kept intact in DB');

echo "\n=== Adding a follow-up turn triggers retro-truncation of the previous pair ===\n";
$h->addMessage(new AssistantMessage('Voici votre fichier.'));
$h->addMessage(new UserMessage('Merci !'));

$raw = loadRawRow($pdo, $tid);
// raw[0] = UserMessage "Generate a CSV"
// raw[1] = ToolCallMessage (was big, should now be elided)
// raw[2] = ToolResultMessage (small ish, no need to elide)
// raw[3] = AssistantMessage "Voici"
// raw[4] = UserMessage "Merci"
assertContains('[elided', $raw[1]['tools'][0]['inputs']['content'], 'previous ToolCall content elided after follow-up turn');
assertContains('50000 bytes written to disk', $raw[1]['tools'][0]['inputs']['content'], 'placeholder mentions size');
assertEq('rep', $raw[1]['tools'][0]['inputs']['filename'], 'filename preserved in elided message');
assertEq('csv', $raw[1]['tools'][0]['inputs']['format'], 'format preserved in elided message');

echo "\n=== Reload after truncation = coherent history ===\n";
// New instance on same thread reads what's in DB and applies SafeSQLChatHistory::load() repairs.
$h2 = new SafeSQLChatHistory($tid, $pdo, 'chat_history', 100000, $truncator);
$messages = $h2->getMessages();
assertEq(5, count($messages), 'reloaded history has 5 messages');
assertEq(UserMessage::class, $messages[0]::class, 'msg 0 = UserMessage');
assertEq(ToolCallMessage::class, $messages[1]::class, 'msg 1 = ToolCallMessage');
assertEq(ToolResultMessage::class, $messages[2]::class, 'msg 2 = ToolResultMessage');
assertEq(AssistantMessage::class, $messages[3]::class, 'msg 3 = AssistantMessage');
assertEq(UserMessage::class, $messages[4]::class, 'msg 4 = UserMessage');
$reloadedCallInputs = $messages[1]->getTools()[0]->getInputs();
assertContains('[elided', $reloadedCallInputs['content'], 'reloaded tool call still elided');

echo "\n=== Default constructor (no truncator passed) uses an 8000-char default ===\n";
$tid2 = 'thread_test_default';
$h3 = new SafeSQLChatHistory($tid2, $pdo, 'chat_history', 100000);
$tool1 = makeTool('dolibarr_files_create', ['filename' => 'x', 'format' => 'csv', 'content' => str_repeat('B', 50000)]);
$h3->addMessage(new ToolCallMessage(null, [$tool1]));
$h3->addMessage(new UserMessage('next'));
$raw2 = loadRawRow($pdo, $tid2);
assertContains('[elided', $raw2[0]['tools'][0]['inputs']['content'], 'default truncator still elides oversized payload');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
