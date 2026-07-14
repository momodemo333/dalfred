<?php

declare(strict_types=1);

/**
 * ConfigServiceModelListsTest — invariants sur les listes de modèles cloud.
 *
 * Garde-fous statiques (aucun appel réseau — la validation de servabilité
 * des IDs se fait via tools/check_models.php) :
 *   1. Chaque provider cloud expose une liste non vide ; ollama reste en
 *      saisie libre (liste vide).
 *   2. Chaque ID respecte les préfixes de son provider — le même contrat
 *      que ConfigService::isModelFromOtherProvider(), qui sert de fallback
 *      quand un client change de provider sans changer de modèle.
 *   3. Chaque modèle exposé dans l'admin a une capacité connue dans
 *      ModelCapacityRegistry (pas le fallback générique de 150k) : le badge
 *      « % de contexte utilisé » est donc juste pour tout modèle proposé.
 *   4. Les modèles clés de la curation courante (juillet 2026) sont présents.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Stub Dolibarr global used by ModelCapacityRegistry's fallback.
$GLOBALS['__test_consts'] = [];
if (!function_exists('getDolGlobalInt')) {
    function getDolGlobalInt(string $name, int $default = 0): int {
        return (int) ($GLOBALS['__test_consts'][$name] ?? $default);
    }
}

use Dalfred\Service\ConfigService;
use Dalfred\Service\ModelCapacityRegistry;

$failures = 0;

function check(bool $ok, string $message): void {
    global $failures;
    if ($ok) {
        echo "  OK    {$message}\n";
    } else {
        echo "  FAIL  {$message}\n";
        $failures++;
    }
}

echo "=== Non-empty curated lists per cloud provider ===\n";
$cloudProviders = ['anthropic', 'openai', 'mistral', 'gemini'];
foreach ($cloudProviders as $provider) {
    check(ConfigService::getModelsForProvider($provider) !== [], "{$provider} list is non-empty");
}
check(ConfigService::getModelsForProvider('ollama') === [], 'ollama stays free-text (empty list)');
check(ConfigService::getModelsForProvider('unknown-provider') === [], 'unknown provider returns empty list');

echo "\n=== Model IDs match their provider's prefixes ===\n";
// Same prefixes as ConfigService::isModelFromOtherProvider(): a model that
// violates this would break the provider-switch fallback in getModel().
$allowedPrefixes = [
    'anthropic' => ['claude-'],
    'openai'    => ['gpt-', 'o3-', 'o4-'],
    'mistral'   => ['mistral-', 'ministral-', 'magistral', 'codestral', 'open-mistral'],
    'gemini'    => ['gemini-'],
];
foreach ($cloudProviders as $provider) {
    foreach (array_keys(ConfigService::getModelsForProvider($provider)) as $modelId) {
        $matches = false;
        foreach ($allowedPrefixes[$provider] as $prefix) {
            if (str_starts_with($modelId, $prefix)) {
                $matches = true;
                break;
            }
        }
        check($matches, "{$provider}: '{$modelId}' starts with an allowed prefix");
    }
}

echo "\n=== Every exposed model has a known capacity (no generic fallback) ===\n";
// With no DALFRED_CONTEXT_WINDOW constant, an unknown model resolves to the
// hardcoded 150_000 fallback. No real capacity in the registry equals that
// value, so hitting it means the registry lacks an entry for a listed model.
$GLOBALS['__test_consts'] = [];
foreach ($cloudProviders as $provider) {
    foreach (array_keys(ConfigService::getModelsForProvider($provider)) as $modelId) {
        $capacity = ModelCapacityRegistry::get($modelId);
        check($capacity !== 150_000, "{$provider}: '{$modelId}' resolves to a real capacity ({$capacity})");
    }
}

echo "\n=== July 2026 curation: key models present, retired ones absent ===\n";
$anthropic = ConfigService::getModelsForProvider('anthropic');
check(isset($anthropic['claude-fable-5']), 'anthropic exposes claude-fable-5');
check(isset($anthropic['claude-sonnet-5']), 'anthropic exposes claude-sonnet-5');
check(isset($anthropic['claude-opus-4-8']), 'anthropic still exposes claude-opus-4-8');
check(isset($anthropic[ConfigService::DEFAULT_MODEL]), 'anthropic list contains DEFAULT_MODEL (' . ConfigService::DEFAULT_MODEL . ')');

$openai = ConfigService::getModelsForProvider('openai');
check(isset($openai['gpt-5.6-sol']), 'openai exposes gpt-5.6-sol');
check(!isset($openai['gpt-5.6-terra']), 'openai omits gpt-5.6-terra until it is usable with the configured API surface/key');
check(!isset($openai['gpt-5.6-luna']), 'openai omits gpt-5.6-luna until rollout is reliable');

$mistral = ConfigService::getModelsForProvider('mistral');
check(isset($mistral['mistral-medium-latest']), 'mistral exposes current medium alias');
check(isset($mistral['ministral-14b-latest']), 'mistral exposes current Ministral family');
check(isset($mistral['magistral-small-latest']), 'mistral exposes non-deprecated reasoning alias');
check(!isset($mistral['magistral-medium-latest']), 'mistral removes deprecated Magistral Medium');

$gemini = ConfigService::getModelsForProvider('gemini');
check(isset($gemini['gemini-3.5-flash']), 'gemini exposes gemini-3.5-flash');
check(isset($gemini['gemini-3.1-pro-preview']), 'gemini exposes current Pro preview');
check(isset($gemini['gemini-3-flash-preview']), 'gemini exposes active Gemini 3 Flash preview');
check(isset($gemini['gemini-2.5-pro']), 'gemini keeps active Gemini 2.5 Pro');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
