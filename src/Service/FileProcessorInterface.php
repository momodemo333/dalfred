<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;

/**
 * Strategy contract for transforming an ingested file into ContentBlocks
 * the LLM can understand. Each file kind (text, image, future PDF/CSV) has
 * its own implementation.
 */
interface FileProcessorInterface
{
    /**
     * Returns true if this processor can handle the given MIME type.
     */
    public function canProcess(string $mimeType): bool;

    /**
     * Transforms the file into one or more ContentBlocks meant for the LLM.
     *
     * @return ContentBlockInterface[]
     * @throws \RuntimeException on unrecoverable processing failure
     */
    public function process(IngestedFile $file): array;

    /**
     * Maximum file size accepted by this processor, in bytes.
     */
    public function getMaxSize(): int;
}
