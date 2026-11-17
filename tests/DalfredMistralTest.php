<?php

declare(strict_types=1);

/*
 * Mistral reasoning models (Mistral Large 4, which mistral-large-latest now
 * points to) answer with `message.content` as an array of blocks — a
 * "thinking" block, then text — instead of a string. NeuronAI 3.15's Mistral
 * provider passes that array to TextContent on a tool call (TypeError) and, on
 * a plain answer, reads `choice.content` instead of `choice.message.content`.
 * Payloads below are shaped on real API answers of 2026-11-17.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Providers\DalfredMistral;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;

$failures = 0;

function check(bool $ok, string $msg, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  OK    ' : '  FAIL  ') . $msg . ($ok || $detail === '' ? '' : "\n        " . $detail) . "\n";
    if (!$ok) {
        $failures++;
    }
}

final class ExposedMistral extends DalfredMistral
{
    public function process(array $result): AssistantMessage
    {
        return $this->processChatResult($result);
    }
}

$provider = new ExposedMistral(key: 'test-key', model: 'mistral-large-4');
$provider->setTools([Tool::make('sum', 'Add two numbers')]);

$thinking = ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'The user wants the sum tool.']], 'closed' => true];
$toolCall = ['id' => '3x4GiS38W', 'type' => 'function', 'function' => ['name' => 'sum', 'arguments' => '{"a": 1234, "b": 4321}'], 'index' => 0];
$usage = ['prompt_tokens' => 95, 'completion_tokens' => 74, 'total_tokens' => 169];

echo "=== Tool call with content as an array (Large 4) ===\n";
try {
    $msg = $provider->process(['choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
        'role' => 'assistant', 'tool_calls' => [$toolCall], 'content' => [$thinking],
    ]]], 'usage' => $usage]);
    check($msg instanceof ToolCallMessage, 'a ToolCallMessage is returned');
    check(count($msg->getTools()) === 1 && $msg->getTools()[0]->getName() === 'sum', 'the sum tool is called');
    check($msg->getTools()[0]->getInputs() === ['a' => 1234, 'b' => 4321], 'arguments are decoded');
    check(!str_contains((string) $msg->getContent(), 'The user wants'), 'reasoning is not presented as text');
} catch (\Throwable $e) {
    check(false, 'tool call with array content', get_class($e) . ': ' . $e->getMessage());
}

echo "=== Tool call with text and thinking blocks ===\n";
try {
    $msg = $provider->process(['choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
        'role' => 'assistant', 'tool_calls' => [$toolCall],
        'content' => [$thinking, ['type' => 'text', 'text' => 'Je calcule.']],
    ]]]]);
    check($msg instanceof ToolCallMessage, 'a ToolCallMessage is returned');
    check((string) $msg->getContent() === 'Je calcule.', 'only the text block is kept', var_export($msg->getContent(), true));
} catch (\Throwable $e) {
    check(false, 'tool call with text + thinking', get_class($e) . ': ' . $e->getMessage());
}

echo "=== Tool call with null content ===\n";
try {
    $msg = $provider->process(['choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
        'role' => 'assistant', 'tool_calls' => [$toolCall], 'content' => null,
    ]]]]);
    check($msg instanceof ToolCallMessage, 'a ToolCallMessage is returned');
} catch (\Throwable $e) {
    check(false, 'tool call with null content', get_class($e) . ': ' . $e->getMessage());
}

echo "=== Final answer with content as an array ===\n";
try {
    $msg = $provider->process(['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
        'role' => 'assistant', 'content' => [$thinking, ['type' => 'text', 'text' => '5555']],
    ]]], 'usage' => $usage]);
    check(!($msg instanceof ToolCallMessage), 'a plain assistant message is returned');
    check((string) $msg->getContent() === '5555', 'the answer is the text block only', var_export($msg->getContent(), true));
    check($msg->getUsage() !== null && $msg->getUsage()->outputTokens === 74, 'usage is kept');
} catch (\Throwable $e) {
    check(false, 'final answer with array content', get_class($e) . ': ' . $e->getMessage());
}

echo "=== String content (every other model) is unchanged ===\n";
$msg = $provider->process(['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '5555']]]]);
check((string) $msg->getContent() === '5555', 'plain string answer');
$msg = $provider->process(['choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
    'role' => 'assistant', 'tool_calls' => [$toolCall], 'content' => '',
]]]]);
check($msg instanceof ToolCallMessage, 'tool call with empty string content');

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
