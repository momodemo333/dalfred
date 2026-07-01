-- Dalfred Knowledge Base
-- Persistent memory for the AI assistant

CREATE TABLE IF NOT EXISTS llx_dalfred_knowledge (
    rowid           INT AUTO_INCREMENT PRIMARY KEY,
    entity          INT NOT NULL DEFAULT 1,
    fk_user         INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    content         TEXT NOT NULL,
    category        VARCHAR(100) DEFAULT NULL,
    scope           VARCHAR(10) NOT NULL DEFAULT 'private',
    date_creation   DATETIME NOT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_knowledge_user (fk_user),
    INDEX idx_knowledge_entity (entity),
    INDEX idx_knowledge_category (category),
    INDEX idx_knowledge_scope (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
