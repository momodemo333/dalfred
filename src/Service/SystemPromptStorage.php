<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * SystemPromptStorage — File-based storage for the user-customizable system prompt.
 *
 * Why a file and not llx_const:
 *   1. llx_const.value is utf8mb3 on many existing Dolibarr installs and rejects
 *      4-byte UTF-8 chars (emojis ≥ U+10000), causing silent saves on a Dolistore
 *      module we cannot guarantee runs on utf8mb4.
 *   2. The prompt is potentially large (Markdown with examples, lists, schemas)
 *      and TEXT (64KB) is fine in theory but the encoding issue alone is a
 *      blocker.
 *   3. A flat file under DOL_DATA_ROOT is editable by hand (FTP, sysadmin) and
 *      backed up by any sane Dolibarr backup strategy that includes documents/.
 *
 * Storage layout:
 *   {DOL_DATA_ROOT}/dalfred/system_prompt.md      — current value
 *
 * Multi-entity:
 *   For entity != 1 we use system_prompt_entity{N}.md to keep entities isolated.
 *
 * The legacy llx_const constant DALFRED_SYSTEM_PROMPT is kept readable as a
 * fallback during migration and can be cleared once everything is on disk.
 */
class SystemPromptStorage
{
    /** Legacy constant kept for migration / fallback reads. */
    public const LEGACY_CONST = 'DALFRED_SYSTEM_PROMPT';

    private string $baseDir;
    private int $entity;

    public function __construct(?string $baseDir = null, int $entity = 1)
    {
        if ($baseDir === null) {
            if (!defined('DOL_DATA_ROOT')) {
                throw new \RuntimeException('DOL_DATA_ROOT is not defined; cannot resolve storage path');
            }
            $baseDir = rtrim(DOL_DATA_ROOT, '/') . '/dalfred';
        }
        $this->baseDir = $baseDir;
        $this->entity = max(1, $entity);
    }

    /**
     * Absolute path to the storage file (per-entity).
     */
    public function getFilePath(): string
    {
        $suffix = $this->entity === 1 ? '' : '_entity' . $this->entity;
        return $this->baseDir . '/system_prompt' . $suffix . '.md';
    }

    /**
     * Read the prompt. Returns '' when the file does not exist or is unreadable.
     *
     * Reads are cheap (one filesystem stat + one read of <100KB typically) and
     * the OS page cache makes repeat reads ~free. We do not cache in-process to
     * stay correct if another request just wrote a new value.
     */
    public function read(): string
    {
        $path = $this->getFilePath();
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $content = @file_get_contents($path);
        return $content === false ? '' : $content;
    }

    /**
     * Write the prompt atomically (temp file + rename).
     *
     * Creates the storage directory if missing.
     *
     * @throws \RuntimeException if the directory cannot be created or the
     *                           write fails — caller MUST surface this to the
     *                           user (the silent-save bug we are fixing).
     */
    public function write(string $content): void
    {
        $this->ensureDir();

        $path = $this->getFilePath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException('Unable to write system prompt to ' . $tmp);
        }
        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to move system prompt to ' . $path);
        }
    }

    /**
     * Delete the stored prompt (does not error if absent).
     */
    public function delete(): bool
    {
        $path = $this->getFilePath();
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }

    /**
     * True when a non-empty prompt is on disk.
     */
    public function exists(): bool
    {
        $path = $this->getFilePath();
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Size of the stored prompt in bytes (0 if absent).
     */
    public function size(): int
    {
        $path = $this->getFilePath();
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * Migrate the legacy llx_const value to a file, if not already done.
     *
     * Idempotent. Returns one of:
     *   'migrated'    — value moved from DB to file
     *   'already'     — file already exists (we trust it)
     *   'no_legacy'   — nothing in DB to migrate
     *   'error'       — write failed; caller should inspect the message
     *
     * The legacy constant is NOT deleted on success — we leave it in place so
     * a downgrade can still find a prompt. It is harmless: read() goes to the
     * file first.
     *
     * @return array{status: string, bytes?: int, message?: string}
     */
    public function migrateFromLegacyConstant(\DoliDB $db): array
    {
        if ($this->exists()) {
            return ['status' => 'already', 'bytes' => $this->size()];
        }

        $legacy = \getDolGlobalString(self::LEGACY_CONST, '');
        if ($legacy === '') {
            return ['status' => 'no_legacy'];
        }

        try {
            $this->write($legacy);
            return ['status' => 'migrated', 'bytes' => strlen($legacy)];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function ensureDir(): void
    {
        if (is_dir($this->baseDir)) {
            return;
        }
        if (!@mkdir($this->baseDir, 0750, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException('Unable to create storage directory ' . $this->baseDir);
        }
    }
}
