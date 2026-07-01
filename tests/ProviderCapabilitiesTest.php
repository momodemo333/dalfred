<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\ProviderCapabilities;

$failures = 0;
function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

function assertEquals($expected, $actual, string $msg): void {
    global $failures;
    if ($expected !== $actual) { echo "  FAIL  $msg (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

echo "=== Anthropic always supports images ===\n";
assertTrue(ProviderCapabilities::supportsImages('anthropic', 'claude-opus-4-7'), 'anthropic / opus 4.7');
assertTrue(ProviderCapabilities::supportsImages('anthropic', 'claude-sonnet-4-6'), 'anthropic / sonnet');

echo "\n=== Gemini always supports images ===\n";
assertTrue(ProviderCapabilities::supportsImages('gemini', 'gemini-2.5-pro'), 'gemini / 2.5 pro');

echo "\n=== OpenAI: vision-capable models only ===\n";
assertTrue(ProviderCapabilities::supportsImages('openai', 'gpt-4o'), 'openai / gpt-4o');
assertTrue(ProviderCapabilities::supportsImages('openai', 'gpt-4-turbo'), 'openai / gpt-4-turbo');
assertTrue(ProviderCapabilities::supportsImages('openai', 'gpt-4-vision-preview'), 'openai / gpt-4-vision');
assertTrue(!ProviderCapabilities::supportsImages('openai', 'gpt-3.5-turbo'), 'openai / gpt-3.5 (no vision)');

echo "\n=== Mistral: only pixtral and recent medium ===\n";
assertTrue(ProviderCapabilities::supportsImages('mistral', 'pixtral-12b'), 'mistral / pixtral');
assertTrue(!ProviderCapabilities::supportsImages('mistral', 'mistral-tiny'), 'mistral / tiny (text only)');
assertTrue(!ProviderCapabilities::supportsImages('mistral', 'mistral-small'), 'mistral / small (text only)');

echo "\n=== Ollama: vision-capable model names ===\n";
assertTrue(ProviderCapabilities::supportsImages('ollama', 'llava:13b'), 'ollama / llava');
assertTrue(ProviderCapabilities::supportsImages('ollama', 'llama3.2-vision'), 'ollama / llama3.2-vision');
assertTrue(!ProviderCapabilities::supportsImages('ollama', 'llama3'), 'ollama / llama3 (no vision)');
assertTrue(!ProviderCapabilities::supportsImages('ollama', 'mistral'), 'ollama / mistral (no vision)');

echo "\n=== Case insensitive ===\n";
assertTrue(ProviderCapabilities::supportsImages('ANTHROPIC', 'Claude-Opus-4-7'), 'anthropic case insensitive');

echo "\n=== Unknown provider returns false ===\n";
assertTrue(!ProviderCapabilities::supportsImages('unknown', 'whatever'), 'unknown provider rejects');

echo "\n--- supportsPdfNative ---\n";

// Anthropic always supports PDF natively.
assertTrue(
    ProviderCapabilities::supportsPdfNative('anthropic', 'claude-sonnet-4-20250514'),
    'Anthropic + claude-sonnet supports native PDF'
);
assertTrue(
    ProviderCapabilities::supportsPdfNative('Anthropic', 'claude-haiku-3'),
    'Anthropic case-insensitive supports native PDF'
);

// Gemini always supports PDF natively.
assertTrue(
    ProviderCapabilities::supportsPdfNative('gemini', 'gemini-2.5-pro'),
    'Gemini + gemini-2.5-pro supports native PDF'
);

// OpenAI: only modern vision models.
assertTrue(
    ProviderCapabilities::supportsPdfNative('openai', 'gpt-4o'),
    'OpenAI + gpt-4o supports native PDF'
);
assertTrue(
    ProviderCapabilities::supportsPdfNative('openai', 'gpt-4.1-mini'),
    'OpenAI + gpt-4.1-mini supports native PDF'
);
assertEquals(
    false,
    ProviderCapabilities::supportsPdfNative('openai', 'gpt-3.5-turbo'),
    'OpenAI + gpt-3.5-turbo does NOT support native PDF'
);

// Mistral and Ollama: never (yet).
assertEquals(
    false,
    ProviderCapabilities::supportsPdfNative('mistral', 'pixtral-large-latest'),
    'Mistral + pixtral does NOT support native PDF'
);
assertEquals(
    false,
    ProviderCapabilities::supportsPdfNative('ollama', 'llava'),
    'Ollama + llava does NOT support native PDF'
);

// Unknown provider.
assertEquals(
    false,
    ProviderCapabilities::supportsPdfNative('unknown-vendor', 'whatever'),
    'Unknown provider does NOT support native PDF'
);

echo "\n";
if ($failures > 0) { echo "FAILED: $failures assertion(s)\n"; exit(1); }
echo "ALL TESTS PASSED\n";
