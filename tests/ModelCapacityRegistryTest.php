<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Stub Dolibarr globals used by ModelCapacityRegistry's fallback.
$GLOBALS['__test_consts'] = [];
if (!function_exists('getDolGlobalInt')) {
    function getDolGlobalInt(string $name, int $default = 0): int {
        return (int) ($GLOBALS['__test_consts'][$name] ?? $default);
    }
}

use Dalfred\Service\ModelCapacityRegistry;

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

echo "=== Exact match ===\n";
assertEq(128_000, ModelCapacityRegistry::get('gpt-4o'), 'gpt-4o exact');
assertEq(400_000, ModelCapacityRegistry::get('gpt-5'), 'gpt-5 exact');
assertEq(1_000_000, ModelCapacityRegistry::get('gpt-4.1'), 'gpt-4.1 exact');
assertEq(200_000, ModelCapacityRegistry::get('claude-sonnet-4'), 'claude-sonnet-4 exact');
assertEq(262_144, ModelCapacityRegistry::get('mistral-small'), 'mistral-small exact');

echo "\n=== Latest-generation context windows ===\n";
assertEq(1_000_000, ModelCapacityRegistry::get('claude-fable-5'),    'fable 5 = 1M');
assertEq(1_000_000, ModelCapacityRegistry::get('claude-sonnet-5'),   'sonnet 5 = 1M');
assertEq(1_050_000, ModelCapacityRegistry::get('gpt-5.6-sol'),       'gpt-5.6-sol = 1.05M (prefix gpt-5.6 wins over gpt-5)');
assertEq(1_050_000, ModelCapacityRegistry::get('gpt-5.6-luna'),      'gpt-5.6-luna = 1.05M');
assertEq(1_050_000, ModelCapacityRegistry::get('gpt-5.6-terra'),     'gpt-5.6-terra = 1.05M');
assertEq(1_000_000, ModelCapacityRegistry::get('claude-opus-4-8'),   'opus 4.8 = 1M');
assertEq(1_000_000, ModelCapacityRegistry::get('claude-opus-4-7'),   'opus 4.7 = 1M');
assertEq(1_000_000, ModelCapacityRegistry::get('claude-sonnet-4-6'), 'sonnet 4.6 = 1M');
assertEq(200_000,   ModelCapacityRegistry::get('claude-opus-4-5'),   'opus 4.5 stays 200k (prefix claude-opus-4)');
assertEq(1_000_000, ModelCapacityRegistry::get('gemini-3.1-pro-preview'), 'gemini 3.1 pro = 1M');
assertEq(1_000_000, ModelCapacityRegistry::get('gemini-3.5-flash'),  'gemini 3.5 flash = 1M');
assertEq(1_000_000, ModelCapacityRegistry::get('gemini-3.1-flash-lite'), 'gemini 3.1 flash-lite = 1M (prefix gemini-3.1-flash)');
assertEq(400_000,   ModelCapacityRegistry::get('gpt-5.5'),           'gpt-5.5 matches gpt-5');
assertEq(400_000,   ModelCapacityRegistry::get('gpt-5.4-mini'),      'gpt-5.4-mini matches gpt-5');
assertEq(262_144,   ModelCapacityRegistry::get('mistral-medium-latest'), 'mistral-medium-latest = 262144');
assertEq(128_000,   ModelCapacityRegistry::get('magistral-medium-latest'), 'magistral-medium-latest = 128k');

echo "\n=== Prefix match (versioned and variant model names) ===\n";
assertEq(200_000, ModelCapacityRegistry::get('claude-3-5-sonnet-20241022'), 'versioned sonnet');
assertEq(128_000, ModelCapacityRegistry::get('gpt-4o-2024-08-06'), 'versioned gpt-4o');
assertEq(400_000, ModelCapacityRegistry::get('gpt-5-mini'), 'gpt-5-mini matches gpt-5');
assertEq(400_000, ModelCapacityRegistry::get('gpt-5-nano'), 'gpt-5-nano matches gpt-5');
assertEq(400_000, ModelCapacityRegistry::get('gpt-5.1'), 'gpt-5.1 matches gpt-5');
assertEq(400_000, ModelCapacityRegistry::get('gpt-5.2'), 'gpt-5.2 matches gpt-5');
assertEq(1_000_000, ModelCapacityRegistry::get('gpt-4.1-mini'), 'gpt-4.1-mini matches gpt-4.1');
assertEq(200_000, ModelCapacityRegistry::get('o3-mini'), 'o3-mini matches o3');
assertEq(200_000, ModelCapacityRegistry::get('o4-mini'), 'o4-mini matches o4');

echo "\n=== Mistral additions ===\n";
assertEq(256_000, ModelCapacityRegistry::get('codestral-latest'),    'codestral-latest prefix match (Codestral 2 = 256k)');
assertEq(128_000, ModelCapacityRegistry::get('open-mistral-nemo'),   'open-mistral-nemo exact');
assertEq(262_144, ModelCapacityRegistry::get('mistral-large-latest'),'mistral-large-latest prefix match');
assertEq(262_144, ModelCapacityRegistry::get('mistral-small-latest'),'mistral-small-latest prefix match');
assertEq(262_144, ModelCapacityRegistry::get('ministral-14b-latest'),'ministral 14B latest');
assertEq(262_144, ModelCapacityRegistry::get('ministral-8b-latest'),'ministral 8B latest');
assertEq(131_072, ModelCapacityRegistry::get('ministral-3b-latest'),'ministral 3B latest');
assertEq(262_144, ModelCapacityRegistry::get('magistral-small-latest'),'magistral small latest');

echo "\n=== Fallback (unknown model uses DALFRED_CONTEXT_WINDOW) ===\n";
$GLOBALS['__test_consts'] = ['DALFRED_CONTEXT_WINDOW' => 150_000];
assertEq(150_000, ModelCapacityRegistry::get('unknown-model-xyz'), 'unknown falls back to config');

echo "\n=== Fallback default when no constant set ===\n";
$GLOBALS['__test_consts'] = [];
assertEq(150_000, ModelCapacityRegistry::get('totally-unknown'), 'unknown with no config falls back to hardcoded default');

echo "\n=== Config set to 0 or negative — falls back to hardcoded default ===\n";
$GLOBALS['__test_consts'] = ['DALFRED_CONTEXT_WINDOW' => 0];
assertEq(150_000, ModelCapacityRegistry::get('unknown'), 'config=0 falls back to hardcoded default');
$GLOBALS['__test_consts'] = ['DALFRED_CONTEXT_WINDOW' => -1];
assertEq(150_000, ModelCapacityRegistry::get('unknown'), 'config=negative falls back to hardcoded default');

echo "\n=== Empty string ===\n";
assertEq(150_000, ModelCapacityRegistry::get(''), 'empty string falls back to default');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
