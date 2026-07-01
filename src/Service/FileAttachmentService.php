<?php

declare(strict_types=1);

namespace Dalfred\Service;

use Dalfred\Chat\ContentBlocks\AttachmentMetaContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use RuntimeException;

use function array_merge;
use function basename;
use function bin2hex;
use function filesize;
use function finfo_close;
use function finfo_file;
use function finfo_open;
use function in_array;
use function is_dir;
use function ltrim;
use function mkdir;
use function move_uploaded_file;
use function pathinfo;
use function preg_replace;
use function random_bytes;
use function rename;
use function rtrim;
use function strlen;
use function substr;

/**
 * Orchestrates file attachments end-to-end:
 *   1. Validate each upload (size, count, MIME via finfo, provider capabilities).
 *   2. Move accepted files into documents/dalfred/attachments/{threadId}/.
 *   3. Build the ContentBlocks payload — both the LLM-facing blocks and the
 *      persisted AttachmentMetaContent meta blocks — given the ingested files
 *      and the user's text message.
 *
 * Filesystem layout: each file gets a UUID prefix to prevent collisions and
 * leak-by-name. The original file name (sanitized) is preserved for display.
 */
final class FileAttachmentService
{
    public const MAX_FILE_SIZE      = 10 * 1024 * 1024;
    public const MAX_FILES_PER_MSG  = 5;

    private const ACCEPTED_MIME_TEXT  = ['text/plain', 'text/markdown', 'text/x-markdown', 'text/x-log', 'text/csv', 'application/csv'];
    private const ACCEPTED_MIME_IMAGE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    private const ACCEPTED_MIME_PDF   = ['application/pdf'];

    /** Where attachments live, relative to DOL_DATA_ROOT. */
    public const STORAGE_SUBDIR = '/dalfred/attachments';

    /** @var FileProcessorInterface[] */
    private array $processors;

    public function __construct(?array $processors = null)
    {
        $this->processors = $processors ?? [
            new TextFileProcessor(),
            new ImageFileProcessor(),
            new PdfNativeProcessor(),
            new PdfTextProcessor(),
        ];
    }

    /**
     * Validate one entry from $_FILES against the per-provider rules.
     * Returns null if accepted, or a human-readable French error message.
     *
     * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $upload
     */
    public function validateUploadedFile(array $upload, string $provider, string $model): ?string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $upload['name'] . ' : erreur d\'upload (code ' . $upload['error'] . ')';
        }
        if ($upload['size'] > self::MAX_FILE_SIZE) {
            return $upload['name'] . ' : trop volumineux (max '
                . round(self::MAX_FILE_SIZE / (1024 * 1024)) . ' MB)';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo === false ? '' : (string) finfo_file($finfo, $upload['tmp_name']);
        if ($finfo !== false) finfo_close($finfo);

        $allAccepted = array_merge(
            self::ACCEPTED_MIME_TEXT,
            self::ACCEPTED_MIME_IMAGE,
            self::ACCEPTED_MIME_PDF
        );
        if (!in_array($detectedMime, $allAccepted, true)) {
            return $upload['name'] . ' : type non supporté (' . $detectedMime . ')';
        }

        // Extension vs detected MIME consistency check (blocks fake.png containing
        // text or fake.pdf containing text or vice-versa).
        $ext         = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $isImageExt  = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
        $isImageMime = in_array($detectedMime, self::ACCEPTED_MIME_IMAGE, true);
        $isPdfExt    = $ext === 'pdf';
        $isPdfMime   = $detectedMime === 'application/pdf';
        if ($isImageExt !== $isImageMime || $isPdfExt !== $isPdfMime) {
            return $upload['name'] . ' : le type réel (' . $detectedMime . ') ne correspond pas à l\'extension';
        }

        // Image and provider doesn't support vision → reject.
        if (in_array($detectedMime, self::ACCEPTED_MIME_IMAGE, true)
            && !ProviderCapabilities::supportsImages($provider, $model)
        ) {
            return $upload['name'] . ' : votre configuration AI ne supporte pas les images';
        }
        return null;
    }

    /**
     * Move an accepted upload into permanent storage and return its IngestedFile.
     *
     * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $upload
     * @throws RuntimeException on storage failure
     */
    public function moveToStorage(array $upload, string $threadId): IngestedFile
    {
        // Ensure both the parent attachments root AND the per-thread subdirectory
        // exist. The parent may be missing on a fresh install where the v2.12.1
        // migration didn't run yet — create it here so the upload doesn't fail
        // silently with a non-actionable error.
        $root = self::storageRoot();
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException(
                'Attachment root not writable: ' . $root
                . ' (the PHP process must be able to create this directory; check ownership and permissions)'
            );
        }
        if (!is_writable($root)) {
            throw new RuntimeException(
                'Attachment root exists but is not writable by the PHP process: ' . $root
                . ' (likely a chown issue — the directory was created by a different user)'
            );
        }

        $threadDir = $root . '/' . self::sanitizeThreadId($threadId);
        if (!is_dir($threadDir) && !@mkdir($threadDir, 0750, true) && !is_dir($threadDir)) {
            throw new RuntimeException('Unable to create attachment thread directory: ' . $threadDir);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = (string) finfo_file($finfo, $upload['tmp_name']);
        finfo_close($finfo);

        $safeName = $this->sanitizeFilename($upload['name']);
        $uuid = bin2hex(random_bytes(8));
        $filename = $uuid . '_' . $safeName;
        $absolute = $threadDir . '/' . $filename;

        // move_uploaded_file works only for real upload tmp files; in tests
        // we may receive a plain temp file → fall back to rename.
        $moved = @move_uploaded_file($upload['tmp_name'], $absolute);
        if (!$moved) {
            $moved = @rename($upload['tmp_name'], $absolute);
        }
        if (!$moved) {
            throw new RuntimeException('Failed to store attachment: ' . $upload['name']);
        }
        @chmod($absolute, 0640);

        return new IngestedFile(
            absolutePath: $absolute,
            relativePath: self::sanitizeThreadId($threadId) . '/' . $filename,
            originalName: $upload['name'],
            mimeType: $detectedMime,
            sizeBytes: filesize($absolute) ?: 0,
        );
    }

    /**
     * Build the ContentBlock array used by the UserMessage:
     *   [user TextContent (if message non-empty)]
     *   [for each file: LLM-facing block(s) + AttachmentMetaContent]
     *
     * `$provider` and `$model` are optional for backward compat. When empty,
     * `ProviderCapabilities::supportsPdfNative('', '')` returns false, so
     * any PDF in `$ingested` falls through to PdfTextProcessor (Smalot
     * extraction) — the safest fallback when the caller has no context.
     *
     * @param IngestedFile[] $ingested
     * @return ContentBlockInterface[]
     */
    public function buildContentBlocks(array $ingested, string $messageText, string $provider = '', string $model = ''): array
    {
        $blocks = [];
        if ($messageText !== '') {
            $blocks[] = new TextContent($messageText);
        }
        foreach ($ingested as $file) {
            $processor = $this->resolveProcessor($file->mimeType, $provider, $model);
            if ($processor === null) {
                $blocks[] = new TextContent('(Pièce jointe ignorée : type non géré pour ' . $file->originalName . ')');
                continue;
            }
            foreach ($processor->process($file) as $b) {
                $blocks[] = $b;
            }
            $blocks[] = new AttachmentMetaContent(
                path: $file->relativePath,
                originalName: $file->originalName,
                mimeType: $file->mimeType,
                sizeBytes: $file->sizeBytes,
                kind: $this->kindOf($file->mimeType),
            );
        }
        return $blocks;
    }

    public function sanitizeFilename(string $name): string
    {
        $base = basename($name);                       // strips path traversal
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? 'file';
        $base = ltrim($base, '.');                     // no leading dot files
        if ($base === '') $base = 'file';
        if (strlen($base) > 50) {
            $info = pathinfo($base);
            $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
            $base = substr($info['filename'] ?? 'file', 0, 50 - strlen($ext)) . $ext;
        }
        return $base;
    }

    private function resolveProcessor(string $mimeType, string $provider = '', string $model = ''): ?FileProcessorInterface
    {
        foreach ($this->processors as $p) {
            if (!$p->canProcess($mimeType)) {
                continue;
            }
            // PDF: pick native vs. text fallback based on provider capability.
            if ($mimeType === 'application/pdf') {
                $isNativeProcessor = $p instanceof PdfNativeProcessor;
                $providerSupports  = ProviderCapabilities::supportsPdfNative($provider, $model);
                if ($isNativeProcessor !== $providerSupports) {
                    continue;
                }
            }
            return $p;
        }
        return null;
    }

    /**
     * Returns the chip "kind" the chat UI uses to pick an icon:
     *   - 'image' → thumbnail rendered from the URL
     *   - 'text'  → file-text Lucide icon (used for .txt, .md, .log AND PDFs)
     * PDFs fall through to 'text' on purpose — adding a dedicated 'pdf' kind
     * would force a UI change for no real win (the file-text icon is fine).
     */
    private function kindOf(string $mimeType): string
    {
        if (in_array($mimeType, self::ACCEPTED_MIME_IMAGE, true)) return 'image';
        return 'text';
    }

    public static function storageRoot(): string
    {
        $root = defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : sys_get_temp_dir();
        return rtrim($root, '/') . self::STORAGE_SUBDIR;
    }

    public static function sanitizeThreadId(string $threadId): string
    {
        // Threads use a known format (thread_{userid}_{date}_{hash}); be strict.
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $threadId) ?? '';
        return $clean === '' ? 'unknown' : $clean;
    }
}
