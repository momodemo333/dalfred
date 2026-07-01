-- ============================================================================
-- Dalfred Module - Threads table (conversation metadata per user)
-- ============================================================================

CREATE TABLE IF NOT EXISTS llx_dalfred_threads (
    rowid           INT AUTO_INCREMENT PRIMARY KEY,
    entity          INT NOT NULL DEFAULT 1,
    fk_user         INT NOT NULL,
    thread_id       VARCHAR(64) NOT NULL,
    title           VARCHAR(255) DEFAULT NULL,
    status          VARCHAR(20) DEFAULT 'active',
    context_page    VARCHAR(500) DEFAULT NULL,
    date_creation   DATETIME NOT NULL,
    date_last_message DATETIME DEFAULT NULL,
    pending_response TINYINT(1) NOT NULL DEFAULT 0,
    pending_since   DATETIME DEFAULT NULL,
    pending_error   TEXT DEFAULT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_dalfred_thread_id (thread_id),
    INDEX idx_dalfred_thread_user (fk_user),
    INDEX idx_dalfred_thread_status (status),
    INDEX idx_dalfred_thread_entity (entity),
    INDEX idx_dalfred_thread_date (date_last_message)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
