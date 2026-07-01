-- ============================================================================
-- Dalfred Module - Activity log table (tool calls, inference events, errors)
-- ============================================================================

CREATE TABLE IF NOT EXISTS llx_dalfred_activity_log (
    rowid           INT AUTO_INCREMENT PRIMARY KEY,
    entity          INT NOT NULL DEFAULT 1,
    fk_user         INT NOT NULL,
    thread_id       VARCHAR(64) DEFAULT NULL,
    event_type      VARCHAR(50) NOT NULL,
    tool_name       VARCHAR(128) DEFAULT NULL,
    tool_inputs     TEXT DEFAULT NULL,
    tool_result     TEXT DEFAULT NULL,
    duration_ms     INT DEFAULT NULL,
    error_message   TEXT DEFAULT NULL,
    exception_class VARCHAR(255) DEFAULT NULL,
    exception_file  VARCHAR(500) DEFAULT NULL,
    stack_trace     MEDIUMTEXT DEFAULT NULL,
    context         TEXT DEFAULT NULL,
    date_creation   DATETIME NOT NULL,

    INDEX idx_dalfred_log_user (fk_user),
    INDEX idx_dalfred_log_thread (thread_id),
    INDEX idx_dalfred_log_event (event_type),
    INDEX idx_dalfred_log_date (date_creation),
    INDEX idx_dalfred_log_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
