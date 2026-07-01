<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Dolibarr cron loader for the ActivityLogPurge job.
 *
 * The Dolibarr cron module loads jobs via dol_include_once() on a relative
 * file path and then new()s a global class. PSR-4 namespaces are not
 * supported by that mechanism, so this thin wrapper bootstraps the module's
 * Composer autoloader and exposes a flat class that delegates to the
 * namespaced service in src/Service/ActivityLogPurgeService.php.
 */
class ActivityLogPurgeService
{
    public function purgeExpired(): int
    {
        return (new \Dalfred\Service\ActivityLogPurgeService())->purgeExpired();
    }
}
