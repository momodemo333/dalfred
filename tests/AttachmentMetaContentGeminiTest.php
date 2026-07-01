<?php

declare(strict_types=1);

/**
 * Regression test for the Gemini TypeError crash on AttachmentMetaContent.
 *
 * Before the fix, AttachmentMetaContent only implemented ContentBlockInterface,
 * but NeuronAI v3's Gemini\MessageMapper::mapContentBlock() type-hints the
 * abstract ContentBlock class. Any conversation containing an attachment chip
 * (live or rehydrated from history) crashed with:
 *
 *   NeuronAI\Providers\Gemini\MessageMapper::mapContentBlock():
 *     Argument #1 ($block) must be of type ContentBlock
 *
 * After the fix, AttachmentMetaContent extends ContentBlock, so the mapper
 * accepts it and silently drops it via its `default => null` branch — the
 * exact intent of the block (LLM never sees it).
 *
 * This test exercises the real mapper directly with the canonical block mix
 * a chat message produces: text + image + attachment-meta.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Chat\ContentBlocks\AttachmentMetaContent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\MessageMapper as GeminiMapper;
use NeuronAI\Providers\Anthropic\MessageMapper as AnthropicMapper;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMapper;

$failures = 0;
function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertEquals($exp, $act, string $msg): void {
    global $failures;
    if ($exp !== $act) {
        echo "  FAIL  $msg\n    expected: " . var_export($exp, true) . "\n    actual:   " . var_export($act, true) . "\n";
        $failures++;
    } else { echo "  OK    $msg\n"; }
}

echo "=== AttachmentMetaContent is a ContentBlock ===\n";
$meta = new AttachmentMetaContent(
    path: 'user_1/foo.txt',
    originalName: 'foo.txt',
    mimeType: 'text/plain',
    sizeBytes: 42,
    kind: 'text',
);
assertTrue($meta instanceof ContentBlock, 'extends ContentBlock (required by Gemini mapper)');
assertEquals('foo.txt', $meta->getContent(), 'getContent() returns the original filename');

echo "\n=== Gemini mapper accepts a message containing an AttachmentMetaContent ===\n";
$msg = new UserMessage([
    new TextContent('Analyse ce fichier'),
    new ImageContent('iVBORw0KGgo=', SourceType::BASE64, 'image/png'),
    $meta,
]);

$mapper = new GeminiMapper();
try {
    $mapped = $mapper->map([$msg]);
    assertTrue(true, 'no TypeError raised by Gemini mapper');
} catch (\TypeError $e) {
    assertTrue(false, 'TypeError leaked from Gemini mapper: ' . $e->getMessage());
    echo "FAIL — regression: Gemini mapper still rejects AttachmentMetaContent\n";
    exit(1);
}

assertEquals(1, count($mapped), 'one mapped message');
$parts = $mapped[0]['parts'];
assertTrue(is_array($parts), 'parts is an array');

// The mapper should keep the text + image and silently drop the meta block.
$kinds = array_map(static function (array $p): string {
    if (isset($p['text'])) return 'text';
    if (isset($p['inline_data'])) return 'image';
    if (isset($p['file_data'])) return 'file';
    return 'unknown';
}, $parts);
assertEquals(['text', 'image'], $kinds, 'meta block filtered out; only text + image survive');

echo "\n=== OpenAI mapper still works (no regression) ===\n";
$openAi = new OpenAIMapper();
try {
    $mappedOpenAi = $openAi->map([$msg]);
    assertTrue(true, 'OpenAI mapper accepts the same message');
    $content = $mappedOpenAi[0]['content'];
    $types = array_column($content, 'type');
    assertTrue(in_array('text', $types, true), 'OpenAI preserves text part');
    assertTrue(in_array('image_url', $types, true), 'OpenAI preserves image part');
    assertEquals(2, count($content), 'OpenAI drops the meta block (2 parts kept)');
} catch (\Throwable $e) {
    assertTrue(false, 'OpenAI mapper regressed: ' . $e->getMessage());
}

echo "\n=== Anthropic mapper still works (no regression) ===\n";
$anthropic = new AnthropicMapper();
try {
    $mappedAnthropic = $anthropic->map([$msg]);
    assertTrue(true, 'Anthropic mapper accepts the same message');
    $content = $mappedAnthropic[0]['content'];
    $types = array_column($content, 'type');
    assertTrue(in_array('text', $types, true), 'Anthropic preserves text part');
    assertTrue(in_array('image', $types, true), 'Anthropic preserves image part');
    assertEquals(2, count($content), 'Anthropic drops the meta block (2 parts kept)');
} catch (\Throwable $e) {
    assertTrue(false, 'Anthropic mapper regressed: ' . $e->getMessage());
}

echo "\n=== Round-trip: serialize → rehydrate → re-map ===\n";
// Simulates the SafeSQLChatHistory load path: persisted array → fromArray() →
// passed back into the mapper. This is the actual code path the customer hits.
$serialized = $meta->toArray();
$rehydrated = AttachmentMetaContent::fromArray($serialized);
assertTrue($rehydrated instanceof ContentBlock, 'rehydrated block is still a ContentBlock');
assertEquals('foo.txt', $rehydrated->originalName, 'originalName preserved through round-trip');
assertEquals(42, $rehydrated->sizeBytes, 'sizeBytes preserved through round-trip');

$msg2 = new UserMessage([new TextContent('coucou'), $rehydrated]);
try {
    $mapper->map([$msg2]);
    assertTrue(true, 'rehydrated meta block does not crash the Gemini mapper');
} catch (\TypeError $e) {
    assertTrue(false, 'rehydrated path still crashes: ' . $e->getMessage());
}

echo "\n" . ($failures === 0 ? "ALL TESTS PASSED\n" : "FAILURES: $failures\n");
exit($failures === 0 ? 0 : 1);
