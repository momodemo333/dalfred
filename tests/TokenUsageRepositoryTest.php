<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', '');
}

use Dalfred\Repository\TokenUsageRepository;

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

// SQLite in-memory schema mirrors the production table.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<SQL
CREATE TABLE dalfred_token_usage (
    rowid INTEGER PRIMARY KEY AUTOINCREMENT,
    entity INTEGER NOT NULL DEFAULT 1,
    fk_user INTEGER NOT NULL,
    thread_id TEXT NOT NULL,
    model TEXT NOT NULL,
    provider TEXT NOT NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    tool_calls_count INTEGER NOT NULL DEFAULT 0,
    context_window INTEGER NOT NULL DEFAULT 0,
    date_creation TEXT NOT NULL,
    tms TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

$repo = new TokenUsageRepository($pdo);

echo "=== insert() + getTotalForThread() ===\n";

$repo->insert([
    'entity' => 1,
    'fk_user' => 5,
    'thread_id' => 'th-abc',
    'model' => 'gpt-4o',
    'provider' => 'openai',
    'input_tokens' => 1000,
    'output_tokens' => 500,
    'duration_ms' => 1234,
    'tool_calls_count' => 2,
    'context_window' => 128000,
]);
$repo->insert([
    'entity' => 1,
    'fk_user' => 5,
    'thread_id' => 'th-abc',
    'model' => 'gpt-4o',
    'provider' => 'openai',
    'input_tokens' => 2000,
    'output_tokens' => 800,
    'duration_ms' => 999,
    'tool_calls_count' => 0,
    'context_window' => 128000,
]);

$totals = $repo->getTotalForThread('th-abc', 1);
assertEq(3000, $totals['input_tokens'], 'cumulated input_tokens');
assertEq(1300, $totals['output_tokens'], 'cumulated output_tokens');
assertEq(2,    $totals['inference_count'], 'inference count');
assertEq('gpt-4o', $totals['last_model'], 'last model');
assertEq(128000, $totals['last_context_window'], 'last context window');
// Last-inference fields — drive the saturation gauge (regression: summed
// inputs double-count history, must not be used as "context used").
assertEq(2000, $totals['last_input_tokens'], 'last_input_tokens = most recent inference input');
assertEq(800,  $totals['last_output_tokens'], 'last_output_tokens = most recent inference output');

echo "\n=== Empty thread returns zeros ===\n";
$empty = $repo->getTotalForThread('nonexistent', 1);
assertEq(0, $empty['input_tokens'], 'empty input');
assertEq(0, $empty['output_tokens'], 'empty output');
assertEq(0, $empty['inference_count'], 'empty count');
assertEq('', $empty['last_model'], 'empty model');
assertEq(0, $empty['last_input_tokens'], 'empty last_input_tokens');
assertEq(0, $empty['last_output_tokens'], 'empty last_output_tokens');

echo "\n=== Entity isolation ===\n";
$repo->insert([
    'entity' => 2,
    'fk_user' => 5,
    'thread_id' => 'th-abc',
    'model' => 'claude-sonnet-4',
    'provider' => 'anthropic',
    'input_tokens' => 999,
    'output_tokens' => 999,
    'duration_ms' => 0,
    'tool_calls_count' => 0,
    'context_window' => 200000,
]);
$entity1 = $repo->getTotalForThread('th-abc', 1);
assertEq(3000, $entity1['input_tokens'], 'entity 1 still 3000');
$entity2 = $repo->getTotalForThread('th-abc', 2);
assertEq(999, $entity2['input_tokens'], 'entity 2 isolated');

echo "\n=== purgeOlderThan() ===\n";
// Insert an old row by manipulating the date_creation directly
$pdo->exec("INSERT INTO dalfred_token_usage (entity, fk_user, thread_id, model, provider, input_tokens, output_tokens, duration_ms, tool_calls_count, context_window, date_creation) VALUES (1, 5, 'old-thread', 'gpt-4o', 'openai', 100, 50, 100, 0, 128000, '2020-01-01 00:00:00')");
$deleted = $repo->purgeOlderThan(1, 90);
assertEq(1, $deleted, 'purged 1 old row');

// After purge:
//   entity 1 / fk_user 5 / thread th-abc — 2 rows (input 3000, output 1300, gpt-4o) → 4300 tokens
//   entity 2 / fk_user 5 / thread th-abc — 1 row (input 999, output 999, claude-sonnet-4)
//
// Add a row under another user to make the filters meaningful.
$repo->insert([
    'entity' => 1, 'fk_user' => 7, 'thread_id' => 'th-other',
    'model' => 'claude-sonnet-4', 'provider' => 'anthropic',
    'input_tokens' => 500, 'output_tokens' => 200, 'duration_ms' => 100,
    'tool_calls_count' => 1, 'context_window' => 200000,
]);

echo "\n=== listForAdmin / countForAdmin — base case ===\n";
$rows = $repo->listForAdmin(1, [], 50, 0);
assertEq(3, count($rows), 'listForAdmin returns the 3 entity-1 rows');
assertEq(3, $repo->countForAdmin(1, []), 'countForAdmin returns 3');

echo "\n=== listForAdmin — filter by user_id (positive) ===\n";
$rows = $repo->listForAdmin(1, ['user_id' => 5], 50, 0);
assertEq(2, count($rows), 'user 5 has 2 rows');
assertEq(2, $repo->countForAdmin(1, ['user_id' => 5]), 'countForAdmin for user 5');

echo "\n=== listForAdmin — filter by user_id = 0 (regression: must be honored, not dropped) ===\n";
$repo->insert([
    'entity' => 1, 'fk_user' => 0, 'thread_id' => 'th-system',
    'model' => 'gpt-4o', 'provider' => 'openai',
    'input_tokens' => 10, 'output_tokens' => 5, 'duration_ms' => 50,
    'tool_calls_count' => 0, 'context_window' => 128000,
]);
$rows = $repo->listForAdmin(1, ['user_id' => 0], 50, 0);
assertEq(1, count($rows), 'user_id=0 filter returns the system user row only');
assertEq(1, $repo->countForAdmin(1, ['user_id' => 0]), 'countForAdmin honors user_id=0');

echo "\n=== listForAdmin — filter by model ===\n";
$rows = $repo->listForAdmin(1, ['model' => 'claude-sonnet-4'], 50, 0);
assertEq(1, count($rows), 'model=claude-sonnet-4 returns 1 row in entity 1');

echo "\n=== listForAdmin — pagination ===\n";
$pageA = $repo->listForAdmin(1, [], 2, 0);
$pageB = $repo->listForAdmin(1, [], 2, 2);
assertEq(2, count($pageA), 'page A returns 2 rows');
assertEq(true, count($pageB) >= 1, 'page B returns at least 1 row');

echo "\n=== getKpis — non-empty 7-day window ===\n";
$kpis = $repo->getKpis(1, 7);
// Rows in entity 1 (all inserted "now"):
//   user 5: 1000+500 + 2000+800 = 4300
//   user 7: 500+200             =  700
//   user 0: 10+5                =   15
// → total tokens = 5015, inferences = 4, top user = 5 with 4300
assertEq(5015, $kpis['total_tokens'], 'kpi total_tokens is sum of recent inputs+outputs');
assertEq(4, $kpis['total_inferences'], 'kpi total_inferences counts 4 entity-1 rows');
assertEq(5, $kpis['top_user_id'], 'top user is user 5');
assertEq(4300, $kpis['top_user_tokens'], 'top user totals = 4300');

echo "\n=== getKpis — empty window ===\n";
$kpisEmpty = $repo->getKpis(999, 7); // entity 999 has no rows
assertEq(0, $kpisEmpty['total_tokens'], 'empty window total = 0');
assertEq(0, $kpisEmpty['total_inferences'], 'empty window count = 0');
assertEq(0, $kpisEmpty['top_user_id'], 'empty window top_user_id = 0');
assertEq('', $kpisEmpty['heaviest_thread_id'], 'empty window heaviest_thread_id = empty string');

echo "\n=== getDailySeries — has at least today's bucket ===\n";
$series = $repo->getDailySeries(1, 30);
assertEq(true, count($series) >= 1, 'series has at least one day');
$today = date('Y-m-d');
$foundToday = false;
foreach ($series as $point) {
    if ($point['date'] === $today) {
        $foundToday = true;
        assertEq(true, $point['tokens'] > 0, "today's bucket has tokens > 0");
    }
}
assertEq(true, $foundToday, "today's date is present in the series");

echo "\n=== Regression: history is re-sent each turn — cumul != context size ===\n";
// Simulate a 3-turn conversation where the input grows each call because the
// provider counts the full prompt = system + history + new user message.
//   turn 1: input 1000, output 50
//   turn 2: input 1100, output 80   (history of turn 1 + new turn 2)
//   turn 3: input 1250, output 90   (history of turns 1-2 + new turn 3)
// The saturation gauge must read the LAST inference only (1250 + 90 = 1340).
// Summing inputs (1000+1100+1250 = 3350) double-counts the conversation history
// — that's exactly the bug fixed alongside this test. Isolated in entity 42 to
// keep the KPI/list/count assertions above stable.
$repo->insert([
    'entity' => 42, 'fk_user' => 9, 'thread_id' => 'th-history',
    'model' => 'gpt-4o', 'provider' => 'openai',
    'input_tokens' => 1000, 'output_tokens' => 50, 'duration_ms' => 100,
    'tool_calls_count' => 0, 'context_window' => 128000,
]);
$repo->insert([
    'entity' => 42, 'fk_user' => 9, 'thread_id' => 'th-history',
    'model' => 'gpt-4o', 'provider' => 'openai',
    'input_tokens' => 1100, 'output_tokens' => 80, 'duration_ms' => 100,
    'tool_calls_count' => 0, 'context_window' => 128000,
]);
$repo->insert([
    'entity' => 42, 'fk_user' => 9, 'thread_id' => 'th-history',
    'model' => 'gpt-4o', 'provider' => 'openai',
    'input_tokens' => 1250, 'output_tokens' => 90, 'duration_ms' => 100,
    'tool_calls_count' => 0, 'context_window' => 128000,
]);
$history = $repo->getTotalForThread('th-history', 42);
assertEq(3350, $history['input_tokens'], 'cumul input across 3 turns');
assertEq(220,  $history['output_tokens'], 'cumul output across 3 turns');
assertEq(3,    $history['inference_count'], 'three inferences');
assertEq(1250, $history['last_input_tokens'], 'saturation gauge uses LAST input (1250), not cumul (3350)');
assertEq(90,   $history['last_output_tokens'], 'saturation gauge uses LAST output (90), not cumul (220)');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
