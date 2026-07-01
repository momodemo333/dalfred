<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use RuntimeException;

use function base64_encode;
use function file_get_contents;

/**
 * Wraps an attached PDF file as a NeuronAI `FileContent` block in BASE64
 * mode, so the per-provider MessageMapper can translate it into the right
 * wire format:
 *   - Anthropic → { "type": "document", "source": { "type": "base64", … } }
 *   - OpenAI    → { "type": "file", "file": { "filename": …, "file_data": "data:application/pdf;base64,…" } }
 *   - Gemini    → { "inline_data": { "mime_type": "application/pdf", "data": … } }
 *
 * Used only when ProviderCapabilities::supportsPdfNative($provider, $model)
 * returns true. Otherwise, FileAttachmentService picks PdfTextProcessor.
 */
final class PdfNativeProcessor implements FileProcessorInterface
{
    public function canProcess(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function process(IngestedFile $file): array
    {
        $bytes = @file_get_contents($file->absolutePath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException(
                'Cannot read PDF: ' . $file->originalName
            );
        }

        return [new FileContent(
            content:    base64_encode($bytes),
            sourceType: SourceType::BASE64,
            mediaType:  'application/pdf',
            filename:   $file->originalName,
        )];
    }

    public function getMaxSize(): int
    {
        return FileAttachmentService::MAX_FILE_SIZE;
    }
}
