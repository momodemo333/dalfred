<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * DalfredMigrations - Defines all database migrations and expected schema for Dalfred
 *
 * Centralized location for:
 * - All versioned migrations (SQL + callbacks)
 * - Expected schema definition for verification
 * - Module version constant
 *
 * Used by: init(), hook auto-migration, maintenance page
 */
class DalfredMigrations
{
    /** Current module version — must match modDalfred::$version */
    public const MODULE_VERSION = '2.25.0';

    /**
     * Models that have been deprecated by their provider and must be remapped
     * to a working successor on upgrade. Customers who chose these models
     * through the admin UI back when they were the default keep them stored
     * in DALFRED_MODEL forever (the descriptor's $this->const never re-runs
     * after first activation). Without this remap, their next chat() would
     * 404 on the provider API.
     *
     * Keep this list short: only entries that are KNOWN to be rejected by
     * the provider's chat endpoint today (verified by direct probe — see
     * dev/issues/2026-05-gemini-deprecation.md when added). Models that are
     * merely "older but still working" don't belong here — the admin can see
     * them in their UI under the "Other / Custom" entry and migrate at will.
     */
    private const DEPRECATED_MODEL_REMAP = [
        // Gemini 2.0 family — Google returns 404 "no longer available to new
        // users" on generateContent since the cohort cutoff around 2026-04.
        // Verified on 2026-05-27 with a fresh API key (a customer environment is the trigger
        // customer for this remap). 2.5-flash is the direct successor.
        'gemini-2.0-flash' => 'gemini-2.5-flash',
        'gemini-2.0-flash-001' => 'gemini-2.5-flash',
        'gemini-2.0-flash-lite' => 'gemini-2.5-flash-lite',
        'gemini-2.0-flash-lite-001' => 'gemini-2.5-flash-lite',
        // Gemini 1.5 family — EOL announced by Google in September 2025.
        'gemini-1.5-pro' => 'gemini-2.5-pro',
        'gemini-1.5-flash' => 'gemini-2.5-flash',
    ];

    /**
     * Create and configure a ModuleMigrationHelper with all Dalfred migrations
     */
    public static function createHelper(\DoliDB $db): ModuleMigrationHelper
    {
        $helper = new ModuleMigrationHelper($db, 'dalfred', self::MODULE_VERSION);

        // === v1.9.0: Add scope column + index to knowledge table ===
        // Both column and index go through addColumnIfMissing/addIndexIfMissing
        // rather than `ADD COLUMN IF NOT EXISTS`. The latter is supported by
        // MariaDB 10.0+ and MySQL 8.0.29+ but raises a *syntax* error (not a
        // duplicate-column error) on MySQL 5.7 and MySQL 8.0 < 8.0.29 — and a
        // syntax error is not absorbed by isIgnorableError, so the migration
        // page showed angry red text even when the column already existed
        // (reported in production at Solar / Chug Express SL, 2026-05-04).
        $helper->addCallbackMigration('1.9.0', function (\DoliDB $db) use ($helper): bool {
            $col = $helper->addColumnIfMissing(
                'dalfred_knowledge',
                'scope',
                "VARCHAR(10) NOT NULL DEFAULT 'private' AFTER category"
            );
            $idx = $helper->addIndexIfMissing('dalfred_knowledge', 'idx_knowledge_scope', 'scope');
            return $col || $idx;
        });

        // === v2.8.0: Add async response columns to threads table ===
        $helper->addCallbackMigration('2.8.0', function (\DoliDB $db) use ($helper): bool {
            $a = $helper->addColumnIfMissing('dalfred_threads', 'pending_response', 'TINYINT(1) NOT NULL DEFAULT 0');
            $b = $helper->addColumnIfMissing('dalfred_threads', 'pending_since', 'DATETIME DEFAULT NULL');
            $c = $helper->addColumnIfMissing('dalfred_threads', 'pending_error', 'TEXT DEFAULT NULL');
            return $a || $b || $c;
        });

        // === v2.11.2: Sync MAIN_MODULE_DALFRED_JS with the module descriptor. ===
        // When new JS files are added to modDalfred::module_parts['js'] (e.g.
        // dalfred-icons.js in 2.11.0), existing installations keep the old list
        // in llx_const until the module is disabled/re-enabled. This migration
        // re-reads the descriptor and pushes the current list to the database,
        // so the widget loads DalfredIcon.js before dalfred.js (fix for
        // "Uncaught ReferenceError: DalfredIcon is not defined").
        $helper->addCallbackMigration('2.11.2', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php'; // dolibarr_set_const
            $modulePath = \dol_buildpath('/dalfred/core/modules/modDalfred.class.php');
            if (!is_file($modulePath)) {
                return false;
            }
            require_once $modulePath;
            $module = new \modDalfred($db);

            global $conf;
            $entity = $conf->entity ?? 1;

            // Re-persist css + js lists from the (fresh) descriptor.
            foreach (['css', 'js'] as $part) {
                if (!isset($module->module_parts[$part]) || !is_array($module->module_parts[$part])) {
                    continue;
                }
                $constName = 'MAIN_MODULE_DALFRED_' . strtoupper($part);
                $value = json_encode($module->module_parts[$part]);
                \dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $entity);
            }
            return true;
        });

        // === v2.12.0: New js/dalfred-attachments.js — same sync pattern as 2.11.2. ===
        // Existing installs need MAIN_MODULE_DALFRED_JS refreshed to include the
        // new file so the widget can render the attach button without throwing
        // "DalfredAttachments is not defined".
        $helper->addCallbackMigration('2.12.0', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            $modulePath = \dol_buildpath('/dalfred/core/modules/modDalfred.class.php');
            if (!is_file($modulePath)) {
                return false;
            }
            require_once $modulePath;
            $module = new \modDalfred($db);

            global $conf;
            $entity = $conf->entity ?? 1;

            foreach (['css', 'js'] as $part) {
                if (!isset($module->module_parts[$part]) || !is_array($module->module_parts[$part])) {
                    continue;
                }
                $constName = 'MAIN_MODULE_DALFRED_' . strtoupper($part);
                $value = json_encode($module->module_parts[$part]);
                \dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $entity);
            }
            return true;
        });

        // === v2.12.1: Ensure documents/dalfred/attachments/ exists and is writable.
        // Bug detected post-2.12.0: in some installs the directory had been
        // created manually as root:root during dev, leaving Apache (www-data)
        // unable to write the upload there. moveToStorage() then threw a silent
        // RuntimeException and $ingestedFiles stayed empty, so the agent
        // answered "no file attached" — confusing.
        // This callback creates the dir under the PHP process owner (so the
        // permissions are right by construction), idempotent.
        $helper->addCallbackMigration('2.12.1', function (\DoliDB $db): bool {
            $dataRoot = defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : null;
            if ($dataRoot === null) {
                return false;
            }
            $attachDir = rtrim($dataRoot, '/') . '/dalfred/attachments';
            if (!is_dir($attachDir)) {
                @mkdir($attachDir, 0750, true);
            }
            // The directory may exist with the wrong owner (created manually
            // before this migration). Try a chmod to at least make it group-
            // writable; chown can't be done from PHP without root.
            @chmod($attachDir, 0750);
            return is_dir($attachDir) && is_writable($attachDir);
        });

        // === v2.12.4: Default DALFRED_ATTACH_ENABLED=1 for installs upgraded
        // from <2.12.0 where the toggle was never persisted to llx_const.
        // The descriptor's $this->const list only runs on first activation;
        // existing installs would never see this value and admins might not
        // realise the feature is available. Use getDolGlobalString without a
        // default to detect "never set" vs "explicitly disabled by user".
        $helper->addCallbackMigration('2.12.4', function (\DoliDB $db): bool {
            global $conf;
            $entity = isset($conf->entity) ? (int) $conf->entity : 1;
            $existing = \getDolGlobalString('DALFRED_ATTACH_ENABLED');
            if ($existing === '') {
                \dolibarr_set_const($db, 'DALFRED_ATTACH_ENABLED', '1', 'chaine', 0, '', $entity);
            }
            return true;
        });

        // === v2.13.8: Move DALFRED_SYSTEM_PROMPT from llx_const to a file. ===
        // Why: llx_const.value is utf8mb3 on most pre-2024 Dolibarr installs
        // and silently rejects any 4-byte UTF-8 char (emojis like 📌, 🚫).
        // dolibarr_set_const() returned -1 but admin/setup.php ignored it and
        // told the user "Configuration saved" — every save with an emoji was
        // lost. The new home is documents/dalfred/system_prompt.md (per-entity
        // suffix for entity != 1), where there is no encoding ceiling.
        //
        // The legacy constant is *replaced* (not deleted) by a short marker
        // 'file:/abs/path' so external tools that still read it can detect the
        // new layout, and downgrades can recover the (now empty) prompt.
        $helper->addCallbackMigration('2.13.8', function (\DoliDB $db): bool {
            global $conf;
            $entity = isset($conf->entity) ? (int) $conf->entity : 1;

            // dolibarr_set_const() lives in admin.lib.php which is NOT loaded
            // on non-admin pages. The hook (printCommonFooter) fires on every
            // page, so we must self-load it here, otherwise the call below
            // would throw and the migration would silently complete without
            // updating the marker constant.
            if (!function_exists('dolibarr_set_const')) {
                require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            }

            $storage = new SystemPromptStorage(null, $entity);
            $result = $storage->migrateFromLegacyConstant($db);

            if ($result['status'] === 'migrated') {
                \dol_syslog(
                    '[Dalfred] system_prompt migrated to file (' . $result['bytes'] . ' bytes)',
                    LOG_INFO
                );
            } elseif ($result['status'] === 'error') {
                \dol_syslog(
                    '[Dalfred] system_prompt migration failed: ' . ($result['message'] ?? 'unknown'),
                    LOG_ERR
                );
                return false;
            }

            // Sync the marker constant whenever a file is present (whether we
            // just wrote it or it was already there from a previous run). This
            // keeps llx_const consistent: a non-empty 'file:...' marker always
            // means the source of truth is the file. Without this, the legacy
            // value could linger and DalfredAgent's fallback would use it.
            if (in_array($result['status'], ['migrated', 'already'], true)) {
                $current = \getDolGlobalString(SystemPromptStorage::LEGACY_CONST, '');
                $expectedMarker = 'file:' . $storage->getFilePath();
                if ($current !== $expectedMarker) {
                    \dolibarr_set_const(
                        $db,
                        SystemPromptStorage::LEGACY_CONST,
                        $expectedMarker,
                        'chaine',
                        0,
                        '',
                        $entity
                    );
                }
                return true;
            }

            // status === 'no_legacy' — nothing to do, not an error.
            return false;
        });

        // === v2.14.0: Slash commands — add command_name column to knowledge table.
        // Why this lives here and not in a new table: a slash command is conceptually
        // a named, reusable prompt — same shape as a knowledge entry (title, content,
        // scope), just with a different usage. Splitting them would duplicate CRUD
        // code, admin UI, and history queries for no benefit.
        $helper->addCallbackMigration('2.14.0', function (\DoliDB $db) use ($helper): bool {
            $col = $helper->addColumnIfMissing(
                'dalfred_knowledge',
                'command_name',
                'VARCHAR(64) DEFAULT NULL'
            );
            $idx = $helper->addIndexIfMissing(
                'dalfred_knowledge',
                'idx_knowledge_command',
                'entity, fk_user, command_name'
            );
            return $col || $idx;
        });

        // === v2.16.3: Repair llx_cronjob.classesname for DalfredPurgeAttachments.
        // Customers who first activated the module between v2.13.0 and v2.13.3
        // got a llx_cronjob row whose `classesname` column was filled with the
        // PHP namespace 'Dalfred\Service\AttachmentPurgeService' (because the
        // descriptor of that era referenced the PSR-4 path the Dolibarr cron
        // loader cannot resolve — see the wrapper added in commit 3c16a9c /
        // v2.13.4). The result was a daily silent failure with the error
        // "Impossible de charger le fichier Dalfred\Service\AttachmentPurgeService"
        // and lastresult=-1 piling up in the cron list.
        //
        // Why disable/enable does not fix it:
        //   - DolibarrModules::delete_cronjobs() only deletes rows where
        //     llx_cronjob.test = '1'. Our cron is declared with
        //     test = '$conf->dalfred->enabled', so the row survives.
        //   - DolibarrModules::insert_cronjobs() then sees the existing row
        //     (matched by label + entity) and skips with `continue`, never
        //     overwriting classesname.
        //
        // This migration force-syncs the row to whatever the descriptor
        // currently declares. Idempotent: a no-op once the value is correct.
        $helper->addCallbackMigration('2.16.3', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
            $modulePath = \dol_buildpath('/dalfred/core/modules/modDalfred.class.php');
            if (!is_file($modulePath)) {
                return false;
            }
            require_once $modulePath;
            $module = new \modDalfred($db);

            if (empty($module->cronjobs) || !is_array($module->cronjobs)) {
                return false;
            }

            global $conf;
            $entity = (int) ($conf->entity ?? 1);
            $repaired = false;

            foreach ($module->cronjobs as $job) {
                $label = $job['label'] ?? '';
                $expectedClass = $job['class'] ?? '';
                if ($label === '' || $expectedClass === '') {
                    continue;
                }

                // Only touch the row if classesname differs — keeps the
                // migration cheap and the log clean on repeated runs.
                $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                    . " SET classesname = '" . $db->escape($expectedClass) . "'"
                    . ", lastresult = NULL"
                    . ", lastoutput = NULL"
                    . ", processing = 0"
                    . " WHERE label = '" . $db->escape($label) . "'"
                    . " AND entity = " . $entity
                    . " AND classesname <> '" . $db->escape($expectedClass) . "'";

                $result = $db->query($sql);
                if ($result) {
                    $affected = $db->affected_rows($result);
                    if ($affected > 0) {
                        $repaired = true;
                        \dol_syslog(
                            '[Dalfred] Repaired llx_cronjob row for "' . $label
                            . '" (entity ' . $entity . '): classesname -> ' . $expectedClass,
                            LOG_NOTICE
                        );
                    }
                }
            }

            return $repaired;
        });

        // === v2.14.0 (bis): Sync MAIN_MODULE_DALFRED_JS with the new dalfred-commands.js.
        // Same rationale as the v2.11.2 callback for dalfred-icons.js: existing
        // installations keep the old js list in llx_const until the module is
        // disabled/re-enabled. Without this, the autocomplete script is never loaded
        // and the slash command UI silently doesn't work for upgraded installs.
        $helper->addCallbackMigration('2.14.0', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            $modulePath = \dol_buildpath('/dalfred/core/modules/modDalfred.class.php');
            if (!is_file($modulePath)) {
                return false;
            }
            require_once $modulePath;
            $module = new \modDalfred($db);

            global $conf;
            $entity = $conf->entity ?? 1;

            foreach (['css', 'js'] as $part) {
                if (!isset($module->module_parts[$part]) || !is_array($module->module_parts[$part])) {
                    continue;
                }
                $constName = 'MAIN_MODULE_DALFRED_' . strtoupper($part);
                $value = json_encode($module->module_parts[$part]);
                \dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $entity);
            }
            return true;
        });

        // === v2.17.0: Seed file generation toolkit defaults on upgrade ===
        // Only the fresh-install path uses $this->const; existing installations
        // never re-run init(). This callback adds the two new constants with
        // their defaults if (and only if) they don't yet exist for this entity.
        $helper->addCallbackMigration('2.17.0', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            global $conf;
            $entity = $conf->entity ?? 1;
            $changed = false;

            if (\getDolGlobalString('DALFRED_FILE_GEN_ENABLED') === '') {
                \dolibarr_set_const($db, 'DALFRED_FILE_GEN_ENABLED', '0', 'chaine', 0, 'Enable file generation by the agent', $entity);
                $changed = true;
            }
            if (\getDolGlobalString('DALFRED_FILE_GEN_MAX_SIZE') === '') {
                \dolibarr_set_const($db, 'DALFRED_FILE_GEN_MAX_SIZE', '5242880', 'chaine', 0, 'Max file size in bytes for generated files', $entity);
                $changed = true;
            }
            return $changed;
        });

        // === v2.19.2: Remap deprecated provider models to their working
        // successor. The trigger is the a customer environment customer (Dolibarr 19, Gemini
        // provider) whose stored DALFRED_MODEL was 'gemini-2.0-flash' — a
        // value that the descriptor's $this->const seeded back when 2.0 was
        // the default. Google has since removed that model from the
        // generateContent endpoint for new cohorts (HTTP 404 "no longer
        // available"), and every chat() call would have started failing
        // silently once the customer's cohort rotated, with no signal in the
        // Dalfred UI other than empty responses.
        //
        // We only remap models that are *known to be rejected* by the
        // provider's chat endpoint today. Models that are merely older but
        // still working are left alone so the admin can keep them visible
        // (the ai_setup.php dropdown's "Other / Custom" handler preserves
        // any non-listed value). The remap list lives at the top of this
        // class so it is easy to audit and extend.
        //
        // Idempotent: the migration is a no-op when DALFRED_MODEL is already
        // a working model.
        $helper->addCallbackMigration('2.19.2', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            global $conf;
            $entity = (int) ($conf->entity ?? 1);

            $currentModel = \getDolGlobalString('DALFRED_MODEL');
            if ($currentModel === '' || !isset(self::DEPRECATED_MODEL_REMAP[$currentModel])) {
                return false;
            }

            $newModel = self::DEPRECATED_MODEL_REMAP[$currentModel];
            \dolibarr_set_const($db, 'DALFRED_MODEL', $newModel, 'chaine', 0, '', $entity);
            \dol_syslog(
                '[Dalfred] Remapped deprecated DALFRED_MODEL "' . $currentModel
                . '" -> "' . $newModel . '" (entity ' . $entity . ')',
                LOG_NOTICE
            );
            return true;
        });

        // === v2.20.0: LLM context observability — token usage table ===
        // Creates llx_dalfred_token_usage to record 1 row per LLM inference.
        // Idempotent: CREATE TABLE IF NOT EXISTS + addIndexIfMissing for each index.
        $helper->addCallbackMigration('2.20.0', function (\DoliDB $db) use ($helper): bool {
            $createSql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "dalfred_token_usage ("
                . "rowid INT AUTO_INCREMENT PRIMARY KEY,"
                . "entity INT NOT NULL DEFAULT 1,"
                . "fk_user INT NOT NULL,"
                . "thread_id VARCHAR(64) NOT NULL,"
                . "model VARCHAR(100) NOT NULL,"
                . "provider VARCHAR(50) NOT NULL,"
                . "input_tokens INT NOT NULL DEFAULT 0,"
                . "output_tokens INT NOT NULL DEFAULT 0,"
                . "duration_ms INT NOT NULL DEFAULT 0,"
                . "tool_calls_count SMALLINT NOT NULL DEFAULT 0,"
                . "context_window INT NOT NULL DEFAULT 0,"
                . "date_creation DATETIME NOT NULL,"
                . "tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $tableCreated = (bool) $db->query($createSql);

            $i1 = $helper->addIndexIfMissing('dalfred_token_usage', 'idx_dalfred_usage_thread', 'thread_id');
            $i2 = $helper->addIndexIfMissing('dalfred_token_usage', 'idx_dalfred_usage_user_date', 'fk_user, date_creation');
            $i3 = $helper->addIndexIfMissing('dalfred_token_usage', 'idx_dalfred_usage_entity_date', 'entity, date_creation');
            $i4 = $helper->addIndexIfMissing('dalfred_token_usage', 'idx_dalfred_usage_model', 'model');

            return $tableCreated || $i1 || $i2 || $i3 || $i4;
        });

        // === v2.22.0: Default DALFRED_MYSQL_SCHEMA_ENABLED=1 for upgrades ===
        // Schema introspection (DESCRIBE / SHOW CREATE TABLE) is now an
        // independent capability from SELECT/WRITE: the agent can learn
        // column names without ever reading row data. Default ON for both
        // fresh installs and upgrades — it dramatically reduces wrong-field
        // failures on Dolibarr tables (note vs note_private, fk_soc vs
        // fk_societe, fields that move between versions). Admins who care
        // about structural leakage can opt out via the toolkit permissions
        // admin page.
        //
        // We only touch the constant when it does NOT exist for this entity,
        // so an admin who has already disabled it won't see it re-enabled
        // on the next minor upgrade.
        $helper->addCallbackMigration('2.22.0', function (\DoliDB $db): bool {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            global $conf;
            $entity = (int) ($conf->entity ?? 1);

            if (\getDolGlobalString('DALFRED_MYSQL_SCHEMA_ENABLED') === '') {
                \dolibarr_set_const(
                    $db,
                    'DALFRED_MYSQL_SCHEMA_ENABLED',
                    '1',
                    'chaine',
                    0,
                    'Enable MySQL schema introspection (DESCRIBE) for Dalfred',
                    $entity
                );
                return true;
            }
            return false;
        });

        // === v2.21.0: Activity log debug enrichment ===
        // Captures stack trace, exception class/file, and run context
        // (provider, model, thread_id, iteration) for error and inference_start
        // events. Required to diagnose customer-reported crashes from a single
        // screenshot of the admin log table.
        //
        // Uses addColumnIfMissing (not `ADD COLUMN IF NOT EXISTS`) for MySQL 5.7
        // compatibility — see comments on the 1.9.0 migration above.
        $helper->addCallbackMigration('2.21.0', function (\DoliDB $db) use ($helper): bool {
            $a = $helper->addColumnIfMissing(
                'dalfred_activity_log',
                'exception_class',
                'VARCHAR(255) DEFAULT NULL AFTER error_message'
            );
            $b = $helper->addColumnIfMissing(
                'dalfred_activity_log',
                'exception_file',
                'VARCHAR(500) DEFAULT NULL AFTER exception_class'
            );
            $c = $helper->addColumnIfMissing(
                'dalfred_activity_log',
                'stack_trace',
                'MEDIUMTEXT DEFAULT NULL AFTER exception_file'
            );
            $d = $helper->addColumnIfMissing(
                'dalfred_activity_log',
                'context',
                'TEXT DEFAULT NULL AFTER stack_trace'
            );
            return $a || $b || $c || $d;
        });

        return $helper;
    }

    /**
     * Expected database schema for verification
     *
     * Only lists columns that were added via migrations (not base CREATE TABLE columns).
     * Format: ['table_name_without_prefix' => ['column_name' => 'TYPE_HINT']]
     */
    public static function getExpectedSchema(): array
    {
        return [
            'dalfred_threads' => [
                'rowid' => 'INT',
                'entity' => 'INT',
                'fk_user' => 'INT',
                'thread_id' => 'VARCHAR',
                'title' => 'VARCHAR',
                'status' => 'VARCHAR',
                'context_page' => 'VARCHAR',
                'date_creation' => 'DATETIME',
                'date_last_message' => 'DATETIME',
                'pending_response' => 'TINYINT',
                'pending_since' => 'DATETIME',
                'pending_error' => 'TEXT',
            ],
            'dalfred_chat_history' => [
                'thread_id' => 'VARCHAR',
                'messages' => 'LONGTEXT',
            ],
            'dalfred_knowledge' => [
                'rowid' => 'INT',
                'entity' => 'INT',
                'fk_user' => 'INT',
                'title' => 'VARCHAR',
                'content' => 'TEXT',
                'category' => 'VARCHAR',
                'scope' => 'VARCHAR',
                'command_name' => 'VARCHAR',
            ],
            'dalfred_token_usage' => [
                'rowid' => 'INT',
                'entity' => 'INT',
                'fk_user' => 'INT',
                'thread_id' => 'VARCHAR',
                'model' => 'VARCHAR',
                'provider' => 'VARCHAR',
                'input_tokens' => 'INT',
                'output_tokens' => 'INT',
                'duration_ms' => 'INT',
                'tool_calls_count' => 'SMALLINT',
                'context_window' => 'INT',
                'date_creation' => 'DATETIME',
            ],
        ];
    }
}
