<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use RuntimeException;

use function base64_encode;
use function file_get_contents;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagecopyresampled;
use function imagedestroy;
use function imagegif;
use function imagejpeg;
use function imagepng;
use function imagesx;
use function imagesy;
use function imagewebp;
use function ob_get_clean;
use function ob_start;
use function round;

/**
 * Loads an image attachment, downscales to MAX_IMAGE_DIM if needed (the longest
 * edge), strips EXIF metadata by re-encoding through GD, base64-encodes and
 * wraps in an ImageContent block ready for the LLM (Anthropic, OpenAI Vision,
 * Gemini, Mistral Pixtral, Ollama Llava, etc.).
 *
 * GD is bundled with every Dolibarr-supported PHP build, so no extra dependency.
 */
final class ImageFileProcessor implements FileProcessorInterface
{
    public const MAX_IMAGE_DIM = 2048;
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const ACCEPTED_MIME = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpeg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function canProcess(string $mimeType): bool
    {
        return isset(self::ACCEPTED_MIME[$mimeType]);
    }

    public function process(IngestedFile $file): array
    {
        $raw = (string) file_get_contents($file->absolutePath);
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            throw new RuntimeException("Cannot decode image: {$file->originalName}");
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $longest = max($w, $h);

        if ($longest > self::MAX_IMAGE_DIM) {
            $ratio = self::MAX_IMAGE_DIM / $longest;
            $newW = (int) round($w * $ratio);
            $newH = (int) round($h * $ratio);
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }

        // Re-encode (this strips EXIF as a side effect — GD output never embeds it).
        $format = self::ACCEPTED_MIME[$file->mimeType];
        ob_start();
        switch ($format) {
            case 'png':  imagepng($img); break;
            case 'jpeg': imagejpeg($img, null, 85); break;
            case 'gif':  imagegif($img); break;
            case 'webp': imagewebp($img, null, 85); break;
        }
        $reEncoded = (string) ob_get_clean();
        imagedestroy($img);

        $base64 = base64_encode($reEncoded);

        return [
            new ImageContent(
                content: $base64,
                sourceType: SourceType::BASE64,
                mediaType: $file->mimeType,
            ),
        ];
    }

    public function getMaxSize(): int
    {
        return self::MAX_FILE_SIZE;
    }
}
