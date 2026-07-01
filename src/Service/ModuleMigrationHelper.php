<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * ModuleMigrationHelper - Reusable database migration system for Dolibarr modules
 *
 * Solves the Dolibarr module update problem: init() only runs on module enable,
 * not on file update. This helper provides:
 *
 * 1. Version tracking via Dolibarr constants (MODULE_DB_VERSION)
 * 2. Auto-detection: compare stored DB version vs module code version
 * 3. Idempotent migrations: safe to run multiple times
 * 4. Manual trigger: "Verify database" button on maintenance page
 * 5. Hook integration: auto-migrate on first page load after update
 *
 * Usage in any module:
 *
 *   $helper = new ModuleMigrationHelper($db, 'mymodule', '1.5.0');
 *   $helper->addMigration('1.2.0', [
 *       "ALTER TABLE llx_mymodule_table ADD COLUMN IF NOT EXISTS col1 INT DEFAULT 0",
 *   ]);
 *   $helper->addMigration('1.5.0', [
 *       "ALTER TABLE llx_mymodule_table ADD COLUMN IF NOT EXISTS col2 VARCHAR(100)",
 *   ]);
 *   $result = $helper->migrateIfNeeded();
 *
 * The helper is designed to be portable — copy this file to any module's
 * src/Service/ or class/ directory and adjust the namespace.
 */
class ModuleMigrationHelper
{
    private \DoliDB $db;
    private string $moduleName;
    private string $currentVersion;
    private string $versionConstant;

    /** @var array<string, string[]> version => SQL statements */
    private array $migrations = [];

    /** @var array<string, callable[]> version => callback functions */
    private array $callbackMigrations = [];

    /** @var string[] Log of executed operations */
    private array $log = [];

    /** @var string[] Errors encountered */
    private array $errors = [];

    /**
     * @param \DoliDB $db           Database handler
     * @param string  $moduleName   Module name (lowercase, e.g., 'dalfred', 'empayment')
     * @param string  $currentVersion Current module version from descriptor (e.g., '2.8.0')
     */
    public function __construct(\DoliDB $db, string $moduleName, string $currentVersion)
    {
        $this->db = $db;
        $this->moduleName = $moduleName;
        $this->currentVersion = $currentVersion;
        $this->versionConstant = strtoupper($moduleName) . '_DB_VERSION';
    }

    /**
     * Register SQL migrations for a specific version
     *
     * All SQL must be idempotent (use IF NOT EXISTS, IF EXISTS, etc.)
     *
     * @param string   $version     Version that introduced these changes (e.g., '2.8.0')
     * @param string[] $sqlStatements Array of SQL statements
     */
    public function addMigration(string $version, array $sqlStatements): self
    {
        $this->migrations[$version] = $sqlStatements;
        return $this;
    }

    /**
     * Register a PHP callback migration for a specific version
     *
     * Useful for complex migrations that require logic (constant migration, data transforms)
     *
     * @param string   $version  Version that introduced this migration
     * @param callable $callback Function that receives ($db) and returns bool
     */
    public function addCallbackMigration(string $version, callable $callback): self
    {
        if (!isset($this->callbackMigrations[$version])) {
            $this->callbackMigrations[$version] = [];
        }
        $this->callbackMigrations[$version][] = $callback;
        return $this;
    }

    /**
     * Check if migrations are needed and run them if so
     *
     * This is the main entry point. Call it from:
     * - Hook (auto-detection on page load)
     * - Maintenance page (manual trigger)
     * - init() method (module enable)
     *
     * @return array{needed: bool, executed: int, errors: string[], log: string[]}
     */
    public function migrateIfNeeded(): array
    {
        $storedVersion = $this->getStoredVersion();

        // No migration needed if versions match
        if ($storedVersion === $this->currentVersion) {
            return [
                'needed' => false,
                'executed' => 0,
                'errors' => [],
                'log' => ['Database is up to date (v' . $this->currentVersion . ')'],
            ];
        }

        return $this->runAllMigrations($storedVersion);
    }

    /**
     * Force run all migrations regardless of stored version
     *
     * Used by maintenance page "Verify database" button.
     * Safe because all migrations are idempotent.
     *
     * @return array{needed: bool, executed: int, errors: string[], log: string[]}
     */
    public function forceRunAll(): array
    {
        return $this->runAllMigrations(null);
    }

    /**
     * Run migrations from a starting version
     *
     * @param string|null $fromVersion Run migrations after this version (null = run all)
     */
    private function runAllMigrations(?string $fromVersion): array
    {
        $this->log = [];
        $this->errors = [];
        $executed = 0;

        // Merge all versions from SQL and callback migrations
        $allVersions = array_unique(array_merge(
            array_keys($this->migrations),
            array_keys($this->callbackMigrations)
        ));

        // Sort by version
        usort($allVersions, 'version_compare');

        foreach ($allVersions as $version) {
            // Skip versions already applied (unless forcing all)
            if ($fromVersion !== null && version_compare($version, $fromVersion, '<=')) {
                continue;
            }

            // Run SQL migrations for this version
            if (isset($this->migrations[$version])) {
                foreach ($this->migrations[$version] as $sql) {
                    $result = $this->db->query($sql);
                    if ($result) {
                        $this->log[] = "[v{$version}] OK: " . $this->truncateSql($sql);
                        $executed++;
                    } else {
                        $error = $this->db->lasterror();
                        // Ignore "already exists" type errors (idempotent)
                        if ($this->isIgnorableError($error)) {
                            $this->log[] = "[v{$version}] SKIP (already applied): " . $this->truncateSql($sql);
                        } else {
                            $this->errors[] = "[v{$version}] ERROR: " . $error . " — SQL: " . $this->truncateSql($sql);
                            $this->log[] = "[v{$version}] ERROR: " . $this->truncateSql($sql);
                        }
                    }
                }
            }

            // Run callback migrations for this version
            if (isset($this->callbackMigrations[$version])) {
                foreach ($this->callbackMigrations[$version] as $i => $callback) {
                    try {
                        $callbackResult = $callback($this->db);
                        $this->log[] = "[v{$version}] Callback #{$i}: " . ($callbackResult ? 'OK' : 'skipped');
                        if ($callbackResult) {
                            $executed++;
                        }
                    } catch (\Exception $e) {
                        $this->errors[] = "[v{$version}] Callback #{$i} ERROR: " . $e->getMessage();
                        $this->log[] = "[v{$version}] Callback #{$i} ERROR: " . $e->getMessage();
                    }
                }
            }
        }

        // Update stored version
        $this->setStoredVersion($this->currentVersion);
        $this->log[] = "DB version updated to v" . $this->currentVersion;

        dol_syslog(
            '[' . ucfirst($this->moduleName) . '] Migration: ' . $executed . ' operations executed, '
            . count($this->errors) . ' errors',
            empty($this->errors) ? LOG_INFO : LOG_WARNING
        );

        return [
            'needed' => true,
            'executed' => $executed,
            'errors' => $this->errors,
            'log' => $this->log,
        ];
    }

    /**
     * Add a column to a table only if it does not exist yet.
     *
     * Why this exists: `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` is supported
     * by MariaDB 10.0+ but rejected by MySQL 5.7 and MySQL 8.0 < 8.0.29 with a
     * raw syntax error (NOT a "duplicate column" error, so it is not absorbed
     * by isIgnorableError). On Dolistore-distributed modules we have no way to
     * guarantee the engine, so the safe portable form is: query SHOW COLUMNS
     * first, then ALTER only if missing.
     *
     * @param string $tableNameWithoutPrefix e.g. 'dalfred_threads' (the helper prepends MAIN_DB_PREFIX)
     * @param string $columnName              e.g. 'pending_response'
     * @param string $columnDefinition        the SQL fragment AFTER `ADD COLUMN <name>`,
     *                                        e.g. "TINYINT(1) NOT NULL DEFAULT 0"
     * @return bool true if the column was added, false if it already existed
     *              or the operation failed (failures get logged in $this->errors)
     */
    public function addColumnIfMissing(string $tableNameWithoutPrefix, string $columnName, string $columnDefinition): bool
    {
        $fullTable = MAIN_DB_PREFIX . $tableNameWithoutPrefix;
        $checkSql = "SHOW COLUMNS FROM " . $fullTable . " LIKE '" . $this->db->escape($columnName) . "'";
        $check = $this->db->query($checkSql);
        if ($check && $this->db->num_rows($check) > 0) {
            return false; // already there — nothing to do
        }

        $alterSql = "ALTER TABLE " . $fullTable . " ADD COLUMN " . $columnName . " " . $columnDefinition;
        $result = $this->db->query($alterSql);
        if (!$result) {
            $this->errors[] = 'addColumnIfMissing(' . $tableNameWithoutPrefix . '.' . $columnName . '): ' . $this->db->lasterror();
            return false;
        }
        return true;
    }

    /**
     * Add an index to a table only if it does not exist yet.
     *
     * Same rationale as addColumnIfMissing — `ADD INDEX IF NOT EXISTS` is not
     * portable across all MySQL versions. We use SHOW INDEX which works
     * everywhere.
     *
     * @param string $tableNameWithoutPrefix e.g. 'dalfred_knowledge'
     * @param string $indexName              e.g. 'idx_knowledge_scope'
     * @param string $indexColumns           comma-separated column list, e.g. 'scope' or 'fk_user, status'
     * @return bool true if the index was added
     */
    public function addIndexIfMissing(string $tableNameWithoutPrefix, string $indexName, string $indexColumns): bool
    {
        $fullTable = MAIN_DB_PREFIX . $tableNameWithoutPrefix;
        $checkSql = "SHOW INDEX FROM " . $fullTable . " WHERE Key_name = '" . $this->db->escape($indexName) . "'";
        $check = $this->db->query($checkSql);
        if ($check && $this->db->num_rows($check) > 0) {
            return false;
        }

        $alterSql = "ALTER TABLE " . $fullTable . " ADD INDEX " . $indexName . " (" . $indexColumns . ")";
        $result = $this->db->query($alterSql);
        if (!$result) {
            $this->errors[] = 'addIndexIfMissing(' . $tableNameWithoutPrefix . '.' . $indexName . '): ' . $this->db->lasterror();
            return false;
        }
        return true;
    }

    /**
     * Verify database schema against expected columns
     *
     * Compares actual table structure with expected definition.
     * Does NOT modify anything — read-only diagnostic.
     *
     * @param array<string, array<string, string>> $expectedSchema
     *   Format: ['table_name' => ['column_name' => 'TYPE', ...], ...]
     *   Example: ['llx_dalfred_threads' => ['pending_response' => 'TINYINT', 'pending_since' => 'DATETIME']]
     *
     * @return array{ok: bool, missing_tables: string[], missing_columns: array<string, string[]>}
     */
    public function verifySchema(array $expectedSchema): array
    {
        $missingTables = [];
        $missingColumns = [];

        foreach ($expectedSchema as $tableName => $columns) {
            $fullTableName = MAIN_DB_PREFIX . $tableName;

            // Check table exists
            $result = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($fullTableName) . "'");
            if (!$result || $this->db->num_rows($result) === 0) {
                $missingTables[] = $tableName;
                continue;
            }

            // Check columns exist
            foreach ($columns as $columnName => $expectedType) {
                $result = $this->db->query(
                    "SHOW COLUMNS FROM " . $fullTableName . " LIKE '" . $this->db->escape($columnName) . "'"
                );
                if (!$result || $this->db->num_rows($result) === 0) {
                    if (!isset($missingColumns[$tableName])) {
                        $missingColumns[$tableName] = [];
                    }
                    $missingColumns[$tableName][] = $columnName;
                }
            }
        }

        return [
            'ok' => empty($missingTables) && empty($missingColumns),
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
        ];
    }

    /**
     * Get the stored database version for this module
     */
    public function getStoredVersion(): string
    {
        return getDolGlobalString($this->versionConstant, '0.0.0');
    }

    /**
     * Store the current database version
     */
    private function setStoredVersion(string $version): void
    {
        global $conf;

        // dolibarr_set_const() lives in core/lib/admin.lib.php which is NOT loaded
        // on every page (e.g. societe/card.php). Since this helper runs from a
        // hook very early in the request lifecycle, we must load it explicitly
        // before calling — otherwise we crash with "undefined function" the first
        // time a non-admin page is hit after a module update.
        if (!function_exists('dolibarr_set_const')) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        }

        dolibarr_set_const(
            $this->db,
            $this->versionConstant,
            $version,
            'chaine',
            0,
            'Database schema version for ' . $this->moduleName . ' module',
            $conf->entity
        );
    }

    /**
     * Check if a DB error is ignorable (already applied migration)
     */
    private function isIgnorableError(string $error): bool
    {
        $ignorable = [
            'Duplicate column name',
            'Duplicate key name',
            'already exists',
            'Can\'t DROP',
            'check that column/key exists',
        ];

        foreach ($ignorable as $pattern) {
            if (stripos($error, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Truncate SQL for logging (avoid huge log entries)
     */
    private function truncateSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return strlen($sql) > 120 ? substr($sql, 0, 117) . '...' : $sql;
    }

    /**
     * Get migration log (after running migrations)
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Get errors (after running migrations)
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
