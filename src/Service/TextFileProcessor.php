<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

use function file_get_contents;
use function mb_convert_encoding;
use function mb_detect_encoding;
use function strlen;
use function substr;

/**
 * Reads a plain-text attachment (.txt, .md, .log, .csv) and packages it as a
 * single TextContent block, framed by visible markers so the LLM
 * understands where the user message ends and the file content begins.
 *
 * Truncates above MAX_TEXT_SIZE (200 KB) to protect the context window.
 * Note: the framed block is re-persisted in the chat history and re-sent to
 * the LLM on every subsequent turn, so this cap bounds the per-turn token
 * cost — it is not just a first-turn limit.
 * Detects non-UTF-8 input and converts to UTF-8 (Anthropic and most
 * providers reject mixed-encoding input).
 */
final class TextFileProcessor implements FileProcessorInterface
{
    public const MAX_TEXT_SIZE = 200 * 1024;

    private const ACCEPTED_MIME = [
        'text/plain'    => true,
        'text/markdown' => true,
        'text/x-log'    => true,
        'text/x-markdown' => true, // some servers serve .md as this
        'text/csv'      => true,
        'application/csv' => true, // some servers/clients serve .csv as this
    ];

    public function canProcess(string $mimeType): bool
    {
        return isset(self::ACCEPTED_MIME[$mimeType]);
    }

    public function process(IngestedFile $file): array
    {
        $raw = (string) file_get_contents($file->absolutePath);

        // Force UTF-8 if needed.
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', $encoding);
        }

        $originalLen = strlen($raw);
        $truncated = false;
        if ($originalLen > self::MAX_TEXT_SIZE) {
            $raw = substr($raw, 0, self::MAX_TEXT_SIZE);
            $truncated = true;
        }

        $framed = "--- Fichier joint : " . $file->originalName . " ---\n"
                . $raw
                . ($truncated
                    ? "\n[... fichier tronqué à " . self::MAX_TEXT_SIZE . " octets sur " . $originalLen . " ...]"
                    : '')
                . "\n--- Fin du fichier ---";

        return [new TextContent($framed)];
    }

    public function getMaxSize(): int
    {
        return self::MAX_TEXT_SIZE;
    }
}
