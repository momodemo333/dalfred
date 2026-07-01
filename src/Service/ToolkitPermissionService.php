<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * ToolkitPermissionService - Manages per-user permissions for Dalfred toolkits
 *
 * This service handles:
 * - Global toolkit activation (via Dolibarr constants)
 * - Per-user permissions for sensitive toolkits (MySQL)
 * - Read/Write permission levels
 */
class ToolkitPermissionService
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;
    protected ?array $cachedPermissions = null;
    protected ConfigService $configService;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
        $this->configService = new ConfigService($db, $entityId);
    }

    // =========================================================================
    // Global Settings (from Dolibarr constants)
    // =========================================================================

    /**
     * Check if MySQL toolkit is globally enabled
     */
    public function isMySQLToolkitGloballyEnabled(): bool
    {
        return $this->configService->getBool('DALFRED_MYSQL_TOOLKIT_ENABLED', false);
    }

    /**
     * Check if MySQL write operations are globally enabled
     */
    public function isMySQLWriteGloballyEnabled(): bool
    {
        return $this->configService->getBool('DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED', false);
    }

    /**
     * Check if MySQL schema introspection is globally enabled.
     *
     * Independent from the read/write toolkits: schema-only is a low-risk
     * helper that lets the agent learn table and column names *without*
     * reading any row. Default true — without this, the LLM regularly
     * guesses wrong column names on Dolibarr tables and produces broken
     * MCP filter clauses (e.g. `note_private` vs `note`, `fk_soc` vs
     * `fk_societe`). Admins can opt out per entity if structural
     * leakage is a concern.
     */
    public function isMySQLSchemaGloballyEnabled(): bool
    {
        return $this->configService->getBool('DALFRED_MYSQL_SCHEMA_ENABLED', true);
    }

    // =========================================================================
    // Per-User Permissions
    // =========================================================================

    /**
     * Check if user has MySQL toolkit access
     *
     * @return bool True if user can use MySQL toolkit
     */
    public function canUserUseMySQLToolkit(): bool
    {
        // First check if globally enabled
        if (!$this->isMySQLToolkitGloballyEnabled()) {
            return false;
        }

        // Then check user-specific permission
        $permissions = $this->getUserPermissions();
        return (bool) ($permissions['mysql_enabled'] ?? false);
    }

    /**
     * Check if user has MySQL write access
     *
     * @return bool True if user can write via MySQL toolkit
     */
    public function canUserWriteMySQL(): bool
    {
        // Must have read access first
        if (!$this->canUserUseMySQLToolkit()) {
            return false;
        }

        // Check if write is globally enabled
        if (!$this->isMySQLWriteGloballyEnabled()) {
            return false;
        }

        // Then check user-specific write permission
        $permissions = $this->getUserPermissions();
        return (bool) ($permissions['mysql_write_enabled'] ?? false);
    }

    /**
     * Get user's toolkit permissions
     *
     * @return array Permissions array
     */
    public function getUserPermissions(): array
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }

        $sql = "SELECT mysql_enabled, mysql_write_enabled
                FROM " . MAIN_DB_PREFIX . "dalfred_toolkit_permissions
                WHERE fk_user = " . (int) $this->userId . "
                AND entity = " . (int) $this->entityId;

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            $this->cachedPermissions = [
                'mysql_enabled' => (bool) $row->mysql_enabled,
                'mysql_write_enabled' => (bool) $row->mysql_write_enabled,
            ];
        } else {
            // Default: no permissions
            $this->cachedPermissions = [
                'mysql_enabled' => false,
                'mysql_write_enabled' => false,
            ];
        }

        return $this->cachedPermissions;
    }

    /**
     * Set user's MySQL toolkit permissions
     *
     * @param bool $enabled Enable MySQL toolkit access
     * @param bool $writeEnabled Enable MySQL write access
     * @param int|null $creatorUserId User ID who is setting the permission
     * @return bool Success
     */
    public function setUserMySQLPermissions(bool $enabled, bool $writeEnabled = false, ?int $creatorUserId = null): bool
    {
        $now = date('Y-m-d H:i:s');

        // Check if record exists
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dalfred_toolkit_permissions
                WHERE fk_user = " . (int) $this->userId . "
                AND entity = " . (int) $this->entityId;

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            // Update
            $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_toolkit_permissions SET
                    mysql_enabled = " . ($enabled ? 1 : 0) . ",
                    mysql_write_enabled = " . ($writeEnabled ? 1 : 0) . ",
                    date_modification = '" . $now . "',
                    fk_user_modif = " . ($creatorUserId ? (int) $creatorUserId : 'NULL') . "
                    WHERE fk_user = " . (int) $this->userId . "
                    AND entity = " . (int) $this->entityId;
        } else {
            // Insert
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_toolkit_permissions
                    (entity, fk_user, mysql_enabled, mysql_write_enabled, date_creation, fk_user_creat)
                    VALUES (
                        " . (int) $this->entityId . ",
                        " . (int) $this->userId . ",
                        " . ($enabled ? 1 : 0) . ",
                        " . ($writeEnabled ? 1 : 0) . ",
                        '" . $now . "',
                        " . ($creatorUserId ? (int) $creatorUserId : 'NULL') . "
                    )";
        }

        $success = (bool) $this->db->query($sql);

        if ($success) {
            // Clear cache
            $this->cachedPermissions = null;
        }

        return $success;
    }

    /**
     * Remove user's toolkit permissions (reset to defaults)
     *
     * @return bool Success
     */
    public function removeUserPermissions(): bool
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_toolkit_permissions
                WHERE fk_user = " . (int) $this->userId . "
                AND entity = " . (int) $this->entityId;

        $success = (bool) $this->db->query($sql);

        if ($success) {
            $this->cachedPermissions = null;
        }

        return $success;
    }

    /**
     * Get all users with toolkit permissions (for admin listing)
     *
     * @return array List of users with their permissions
     */
    public function getAllUsersWithPermissions(): array
    {
        $sql = "SELECT p.*, u.login, u.lastname, u.firstname
                FROM " . MAIN_DB_PREFIX . "dalfred_toolkit_permissions p
                LEFT JOIN " . MAIN_DB_PREFIX . "user u ON p.fk_user = u.rowid
                WHERE p.entity = " . (int) $this->entityId . "
                ORDER BY u.login";

        $result = $this->db->query($sql);
        $users = [];

        if ($result) {
            while ($row = $this->db->fetch_object($result)) {
                $users[] = [
                    'rowid' => (int) $row->rowid,
                    'fk_user' => (int) $row->fk_user,
                    'login' => $row->login,
                    'fullname' => trim($row->firstname . ' ' . $row->lastname),
                    'mysql_enabled' => (bool) $row->mysql_enabled,
                    'mysql_write_enabled' => (bool) $row->mysql_write_enabled,
                    'date_creation' => $row->date_creation,
                    'date_modification' => $row->date_modification,
                ];
            }
        }

        return $users;
    }

    /**
     * Get summary of permissions for current user (for debug/display)
     *
     * @return array Summary of all toolkit permissions
     */
    public function getPermissionsSummary(): array
    {
        return [
            'global' => [
                'mysql_toolkit_enabled' => $this->isMySQLToolkitGloballyEnabled(),
                'mysql_write_enabled' => $this->isMySQLWriteGloballyEnabled(),
                'mysql_schema_enabled' => $this->isMySQLSchemaGloballyEnabled(),
            ],
            'user' => $this->getUserPermissions(),
            'effective' => [
                'can_use_mysql' => $this->canUserUseMySQLToolkit(),
                'can_write_mysql' => $this->canUserWriteMySQL(),
                'can_use_schema' => $this->isMySQLSchemaGloballyEnabled(),
            ],
        ];
    }

    /**
     * Clear cached permissions
     */
    public function clearCache(): void
    {
        $this->cachedPermissions = null;
    }
}
