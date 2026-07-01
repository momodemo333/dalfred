<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * Maps an LLM model name to its known context window (max input+output tokens).
 *
 * Used by the token usage observability layer to compute "X% of context used"
 * in the chat badge and admin dashboard.
 *
 * Maintenance: update CAPACITIES at each Dalfred release when a new model
 * comes out. Fallback on DALFRED_CONTEXT_WINDOW (default 150 000) is always
 * available so unknown models never break the badge.
 */
final class ModelCapacityRegistry
{
    /** @var array<string,int> exact model name → context window in tokens */
    private const CAPACITIES = [
        // OpenAI — keys cover every model exposed in ConfigService::getModelsForProvider('openai').
        // Variants (e.g. gpt-5.5, gpt-5.4-mini, gpt-5-mini, gpt-4.1-mini, o3-mini, o4-mini) resolve via longest-prefix match.
        'gpt-5'              => 400_000,
        'gpt-4.1'            => 1_000_000,
        'gpt-4o'             => 128_000,
        'o3'                 => 200_000,
        'o4'                 => 200_000,
        // Anthropic — Opus 4.7+ and Sonnet 4.6 ship a 1M context window; older 4.x stay at 200k.
        'claude-opus-4-8'    => 1_000_000,
        'claude-opus-4-7'    => 1_000_000,
        'claude-sonnet-4-6'  => 1_000_000,
        'claude-sonnet-4'    => 200_000,
        'claude-haiku-4'     => 200_000,
        'claude-opus-4'      => 200_000,
        'claude-3-5-sonnet'  => 200_000,
        'claude-3-5-haiku'   => 200_000,
        // Mistral — *-latest variants resolve via prefix match.
        'mistral-large'      => 128_000,
        'mistral-medium'     => 128_000,
        'mistral-small'      =>  32_000,
        'magistral-medium'   => 128_000,
        'codestral'          => 256_000,
        'open-mistral-nemo'  => 128_000,
        // Google — Gemini 3.x Pro carries a 2M window, Flash/Flash-Lite 1M.
        'gemini-3.1-pro'     => 2_000_000,
        'gemini-3.5-flash'   => 1_000_000,
        'gemini-3.1-flash'   => 1_000_000,
        'gemini-3-flash'     => 1_000_000,
        'gemini-2.5-pro'     => 1_000_000,
        'gemini-2.5-flash'   => 1_000_000,
        'gemini-2.5-flash-lite' => 1_000_000,
        // Meta / others
        'llama-3.1-70b'      => 128_000,
        'llama-3.1-8b'       => 128_000,
    ];

    private const FALLBACK_DEFAULT = 150_000;

    /**
     * Resolve a context window for the given model name.
     *
     * Algorithm:
     *   1. Exact match → return value.
     *   2. Longest-prefix match → return value (e.g. 'claude-3-5-sonnet-20241022' → 'claude-3-5-sonnet').
     *   3. Fallback on DALFRED_CONTEXT_WINDOW config constant.
     *   4. Final fallback on FALLBACK_DEFAULT (150_000).
     *
     * Never throws.
     */
    public static function get(string $modelName): int
    {
        if ($modelName === '') {
            return self::resolveFallback();
        }

        if (isset(self::CAPACITIES[$modelName])) {
            return self::CAPACITIES[$modelName];
        }

        // Longest-prefix match (sort keys by length desc to match the most specific first).
        $keys = array_keys(self::CAPACITIES);
        usort($keys, static fn(string $a, string $b): int => strlen($b) - strlen($a));
        foreach ($keys as $key) {
            if (str_starts_with($modelName, $key)) {
                return self::CAPACITIES[$key];
            }
        }

        return self::resolveFallback();
    }

    private static function resolveFallback(): int
    {
        if (function_exists('getDolGlobalInt')) {
            $v = getDolGlobalInt('DALFRED_CONTEXT_WINDOW', self::FALLBACK_DEFAULT);
            return $v > 0 ? $v : self::FALLBACK_DEFAULT;
        }
        return self::FALLBACK_DEFAULT;
    }
}
