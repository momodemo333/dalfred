<?php

declare(strict_types=1);

namespace Dalfred\Service;

use function in_array;
use function str_contains;
use function strtolower;

/**
 * Tells whether a (provider, model) pair can accept image content blocks.
 *
 * Used both by the chat UI (to filter the file picker accept= attribute)
 * and by the backend (to defensively reject image uploads when the
 * configured provider would just throw at the LLM call).
 *
 * The matcher uses case-insensitive substring checks against the model
 * name. Add new entries when a vendor releases a new vision model.
 */
final class ProviderCapabilities
{
    /**
     * Substring patterns considered vision-capable per provider.
     * Update this when a new vision model ships.
     */
    private const VISION_MODELS = [
        'openai'  => ['gpt-5', 'gpt-4o', 'gpt-4-turbo', 'gpt-4-vision', 'gpt-4.1', 'o1', 'o3'],
        'mistral' => ['pixtral', 'mistral-large', 'mistral-medium', 'mistral-small', 'ministral-', 'magistral-small'],
        'ollama'  => ['llava', 'bakllava', 'llama3.2-vision', 'minicpm-v', 'moondream'],
    ];

    /**
     * Providers whose every model handles images.
     */
    private const ALWAYS_VISION = ['anthropic', 'gemini'];

    public static function supportsImages(string $provider, string $model): bool
    {
        $provider = strtolower($provider);
        $model = strtolower($model);

        if (in_array($provider, self::ALWAYS_VISION, true)) {
            return true;
        }

        if (!isset(self::VISION_MODELS[$provider])) {
            return false;
        }

        foreach (self::VISION_MODELS[$provider] as $needle) {
            if (str_contains($model, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Substring patterns considered PDF-native per provider.
     *
     * Sources (verify before bumping):
     *   - OpenAI:  https://platform.openai.com/docs/guides/pdf-files
     *              Confirms PDF support on gpt-4o family and reasoning
     *              models (o1, o3). gpt-4-turbo accepts PDFs via the
     *              Responses API too.
     *   - Anthropic: https://docs.anthropic.com/en/docs/build-with-claude/pdf-support
     *              All Claude models accept PDF documents — no list to maintain.
     *   - Gemini:  https://ai.google.dev/gemini-api/docs/document-processing
     *              All Gemini models accept inline PDF data — no list to maintain.
     *
     * Update PDF_NATIVE_MODELS when a vendor adds/removes a PDF-capable
     * variant. Anthropic and Gemini live in ALWAYS_PDF_NATIVE because
     * coverage is provider-wide.
     */
    private const PDF_NATIVE_MODELS = [
        'openai' => ['gpt-5', 'gpt-4o', 'gpt-4-turbo', 'gpt-4.1', 'o1', 'o3'],
    ];

    /**
     * Providers whose every model handles PDF documents natively.
     */
    private const ALWAYS_PDF_NATIVE = ['anthropic', 'gemini'];

    public static function supportsPdfNative(string $provider, string $model): bool
    {
        $provider = strtolower($provider);
        $model = strtolower($model);

        if (in_array($provider, self::ALWAYS_PDF_NATIVE, true)) {
            return true;
        }

        if (!isset(self::PDF_NATIVE_MODELS[$provider])) {
            return false;
        }

        foreach (self::PDF_NATIVE_MODELS[$provider] as $needle) {
            if (str_contains($model, $needle)) {
                return true;
            }
        }
        return false;
    }
}
