<?php

declare(strict_types=1);

namespace Dalfred\Service;

use function dol_syslog;
use function file_exists;
use function filemtime;
use function glob;
use function is_dir;
use function rmdir;
use function time;
use function unlink;

/**
 * Cron-invoked sweeper that removes attachment files older than TTL_DAYS.
 *
 * Declared in modDalfred::$cronjobs as a daily 'method' job, instantiated by
 * Dolibarr through the Cron module. The class itself has zero state and
 * no constructor arg, so Dolibarr can new it() without injection.
 */
final class AttachmentPurgeService
{
    public const TTL_DAYS = 7;

    /**
     * Walks documents/dalfred/attachments/ and removes files older than 7 days.
     * Removes empty {threadId}/ directories afterwards.
     *
     * Dolibarr's cron infrastructure expects an int return code: 0 on success,
     * <0 on error. The detail counts are written to dolibarr.log.
     */
    public function purgeExpired(): int
    {
        $root = FileAttachmentService::storageRoot();
        if (!is_dir($root)) {
            return 0; // nothing to do, success.
        }

        $cutoff = time() - (self::TTL_DAYS * 86400);
        $purgedFiles = 0;
        $removedDirs = 0;
        $errors = 0;

        // First pass: delete expired files.
        $threadDirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($threadDirs as $dir) {
            $files = glob($dir . '/*') ?: [];
            foreach ($files as $f) {
                if (!is_file($f)) {
                    continue;
                }
                $mtime = @filemtime($f);
                if ($mtime !== false && $mtime < $cutoff) {
                    if (@unlink($f)) {
                        $purgedFiles++;
                    } else {
                        $errors++;
                    }
                }
            }
        }

        // Second pass: remove now-empty thread dirs.
        foreach ($threadDirs as $dir) {
            $remaining = glob($dir . '/*') ?: [];
            if ($remaining === [] && @rmdir($dir)) {
                $removedDirs++;
            }
        }

        if (function_exists('dol_syslog')) {
            dol_syslog(
                '[DALFRED] AttachmentPurge: ' . $purgedFiles . ' files, ' . $removedDirs
                . ' empty dirs, ' . $errors . ' errors',
                $errors > 0 ? LOG_WARNING : LOG_INFO
            );
        }

        return 0;
    }
}
