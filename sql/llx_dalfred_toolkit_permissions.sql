-- ============================================================================
-- Dalfred Module - Toolkit Permissions table
-- ============================================================================
-- This table stores per-user permissions for toolkits (MySQL, etc.)
-- Allows granular control over which users can access database toolkits
-- and whether they have read-only or read-write access.

CREATE TABLE IF NOT EXISTS llx_dalfred_toolkit_permissions (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity              INTEGER DEFAULT 1 NOT NULL,
    fk_user             INTEGER NOT NULL,

    -- MySQL Toolkit permissions
    mysql_enabled       TINYINT(1) DEFAULT 0 NOT NULL,
    mysql_write_enabled TINYINT(1) DEFAULT 0 NOT NULL,

    -- Metadata
    date_creation       DATETIME NOT NULL,
    date_modification   DATETIME,
    fk_user_creat       INTEGER,
    fk_user_modif       INTEGER,

    UNIQUE KEY uk_dalfred_toolkit_perm_user (entity, fk_user),
    INDEX idx_dalfred_toolkit_perm_entity (entity),
    INDEX idx_dalfred_toolkit_perm_user (fk_user),

    CONSTRAINT fk_dalfred_toolkit_perm_user FOREIGN KEY (fk_user)
        REFERENCES llx_user(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
