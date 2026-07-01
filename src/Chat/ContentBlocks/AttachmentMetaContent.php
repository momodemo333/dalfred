<?php

declare(strict_types=1);

namespace Dalfred\Chat\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;

/**
 * Custom ContentBlock that stores ONLY the metadata of an attached file.
 *
 * Persisted in llx_dalfred_chat_history alongside the regular ContentBlocks
 * so the chat UI can re-render the file chips even after the physical file
 * has been purged (TTL 7 days). The LLM never sees this block — it lives
 * outside the canonical ContentBlockType enum, so every provider's
 * MessageMapper falls into its `default => null` branch and array_filter
 * drops it before the request is sent.
 *
 * Extends ContentBlock (the abstract base) rather than implementing
 * ContentBlockInterface directly: the Gemini MessageMapper in NeuronAI v3
 * (still true on 3.14.6) type-hints `ContentBlock` instead of the interface,
 * so any custom block that only implements the interface triggers a
 * TypeError when Gemini iterates its parts. Extending the abstract class
 * is the contract every provider actually expects.
 *
 * The discriminant string used for serialization is "dalfred_attachment_meta"
 * to avoid colliding with any future official type added by NeuronAI.
 */
final class AttachmentMetaContent extends ContentBlock
{
    public const TYPE_DISCRIMINANT = 'dalfred_attachment_meta';

    public function __construct(
        public readonly string $path,         // relative to documents/dalfred/attachments/
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $kind,         // 'text' | 'image'
    ) {
        // ContentBlock's constructor stores a mutable $content. We surface the
        // original filename there so getContent() / accumulateContent() inherited
        // from the base class behave sensibly if any framework code introspects
        // the block generically.
        parent::__construct($originalName);
    }

    public function getType(): ContentBlockType
    {
        // Cast — there is no "attachment_meta" case in ContentBlockType, but the
        // interface contract requires a ContentBlockType. We pick TEXT as the
        // safest default so that any consumer iterating typed cases doesn't
        // crash. The real discriminant lives in the serialized 'type' field
        // (TYPE_DISCRIMINANT) which we control end-to-end.
        return ContentBlockType::TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => self::TYPE_DISCRIMINANT,
            'path' => $this->path,
            'originalName' => $this->originalName,
            'mimeType' => $this->mimeType,
            'sizeBytes' => $this->sizeBytes,
            'kind' => $this->kind,
            'meta' => $this->meta,
        ];
    }

    /**
     * Rehydrate from the serialized array (called by the SafeSQLChatHistory override).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self(
            path: (string)($data['path'] ?? ''),
            originalName: (string)($data['originalName'] ?? ''),
            mimeType: (string)($data['mimeType'] ?? 'application/octet-stream'),
            sizeBytes: (int)($data['sizeBytes'] ?? 0),
            kind: (string)($data['kind'] ?? 'text'),
        );
        if (isset($data['meta']) && is_array($data['meta'])) {
            $instance->setMetadata($data['meta']);
        }
        return $instance;
    }
}
