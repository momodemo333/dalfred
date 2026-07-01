<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * Immutable DTO describing one file that has been validated and stored
 * inside documents/dalfred/attachments/{threadId}/. Created by
 * FileAttachmentService::ingest(), consumed by FileProcessorInterface
 * implementations to build the corresponding ContentBlocks.
 */
final class IngestedFile
{
    public function __construct(
        public readonly string $absolutePath,    // /var/documents/dalfred/attachments/t1/uuid_name.png
        public readonly string $relativePath,    // t1/uuid_name.png  (relative to attachments/)
        public readonly string $originalName,    // logo.png
        public readonly string $mimeType,        // image/png
        public readonly int    $sizeBytes,
    ) {
    }
}
