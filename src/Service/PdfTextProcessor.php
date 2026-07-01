<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use RuntimeException;
use Smalot\PdfParser\Parser as SmalotParser;
use Throwable;

use function count;
use function strlen;
use function substr;
use function trim;

/**
 * Fallback PDF processor for providers without native PDF support
 * (Mistral, Ollama, OpenAI < gpt-4o, …). Uses Smalot\PdfParser to
 * extract the text layer and wraps it in `--- PDF joint : NOM (N pages) ---`
 * / `--- Fin du PDF ---` markers, with a page count to hint at lost layout.
 *
 * Truncates above MAX_TEXT_SIZE (50 KB, same as TextFileProcessor) to
 * protect the context window. Throws RuntimeException with a French
 * user-facing message on encrypted/corrupt PDFs and on PDFs whose text
 * layer is empty (image-only scans) — the caller routes the message
 * through `attachment_errors[]` to the chat UI.
 *
 * Memory note: Smalot loads the entire PDF into memory during parsing,
 * which can spike to ~5-10x the file size. A 10 MB PDF (the module's
 * MAX_FILE_SIZE) can therefore push usage past PHP's default 128 MB
 * memory_limit on tight environments. Operators serving close to the
 * limit should bump `memory_limit` to 256 MB.
 */
final class PdfTextProcessor implements FileProcessorInterface
{
    public const MAX_TEXT_SIZE = 50 * 1024;

    public function canProcess(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function process(IngestedFile $file): array
    {
        try {
            $parser = new SmalotParser();
            $doc    = $parser->parseFile($file->absolutePath);
            $pages  = count($doc->getPages());
            $raw    = trim($doc->getText());
        } catch (Throwable $e) {
            throw new RuntimeException(
                $file->originalName . ' : impossible de lire le PDF '
                . '(chiffré, corrompu ou sans couche texte). Essayez avec '
                . 'Anthropic, OpenAI ou Gemini pour la lecture native.'
            );
        }

        if ($raw === '') {
            // Smalot returns empty text both for image-only scans AND for
            // some encrypted PDFs that didn't trigger a parse exception.
            // The message keeps "chiffré" so the user gets the same hint
            // regardless of which branch was hit.
            throw new RuntimeException(
                $file->originalName . ' : ce PDF est chiffré, ne contient pas '
                . 'de texte extractible (image scannée), ou est vide. '
                . 'Essayez avec Anthropic, OpenAI ou Gemini pour la lecture '
                . 'native (OCR).'
            );
        }

        $originalLen = strlen($raw);
        $truncated   = false;
        if ($originalLen > self::MAX_TEXT_SIZE) {
            $raw       = substr($raw, 0, self::MAX_TEXT_SIZE);
            $truncated = true;
        }

        $pageLabel = $pages . ' page' . ($pages > 1 ? 's' : '');
        $framed    = "--- PDF joint : " . $file->originalName . " (" . $pageLabel . ") ---\n"
                   . $raw
                   . ($truncated
                       ? "\n[... fichier tronqué à " . self::MAX_TEXT_SIZE . " octets sur " . $originalLen . " ...]"
                       : '')
                   . "\n--- Fin du PDF ---";

        return [new TextContent($framed)];
    }

    public function getMaxSize(): int
    {
        return FileAttachmentService::MAX_FILE_SIZE;
    }
}
