<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * Deletes activity_log rows older than DALFRED_LOG_RETENTION_DAYS (default 30).
 *
 * Cron-friendly: idempotent, returns 0 on success / -1 on DB error (Dolibarr
 * cron contract: any non-zero return is treated as an error), never throws.
 */
final class ActivityLogPurgeService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public function purgeExpired(): int
    {
        global $db, $conf;

        $retention = getDolGlobalInt('DALFRED_LOG_RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS);
        if ($retention <= 0) {
            if (function_exists('dol_syslog')) {
                dol_syslog('[DALFRED] ActivityLogPurge: retention <= 0, skipping', LOG_INFO);
            }
            return 0;
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_activity_log"
            . " WHERE date_creation < DATE_SUB(NOW(), INTERVAL " . (int) $retention . " DAY)"
            . " AND entity = " . (int) $conf->entity;

        $resql = $db->query($sql);
        if (!$resql) {
            if (function_exists('dol_syslog')) {
                dol_syslog('[DALFRED] ActivityLogPurge failed: ' . $db->lasterror(), LOG_ERR);
            }
            return -1; // Dolibarr cron treats negative/non-zero as error
        }

        $deleted = (int) $db->affected_rows($resql);
        if (function_exists('dol_syslog')) {
            dol_syslog('[DALFRED] ActivityLogPurge: deleted ' . $deleted . ' rows (retention=' . $retention . 'd)', LOG_INFO);
        }
        return 0; // Dolibarr cron: 0 = success, non-zero = error
    }
}
