-- Dalfred Smart Queries
-- Saved SQL queries for reuse via the AI assistant

CREATE TABLE IF NOT EXISTS llx_dalfred_smart_queries (
    rowid           INT AUTO_INCREMENT PRIMARY KEY,
    entity          INT NOT NULL DEFAULT 1,
    fk_user         INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    sql_query       TEXT NOT NULL,
    category        VARCHAR(100) DEFAULT NULL,
    parameters      TEXT DEFAULT NULL,
    scope           VARCHAR(10) NOT NULL DEFAULT 'private',
    last_execution  DATETIME DEFAULT NULL,
    execution_count INT NOT NULL DEFAULT 0,
    date_creation   DATETIME NOT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_smartquery_user (fk_user),
    INDEX idx_smartquery_entity (entity),
    INDEX idx_smartquery_category (category),
    INDEX idx_smartquery_scope (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
