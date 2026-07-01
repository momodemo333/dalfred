-- ============================================================================
-- Dalfred Module - Token usage table (1 row per LLM inference)
-- Introduced in v2.20.0 (LLM context observability)
-- ============================================================================

CREATE TABLE IF NOT EXISTS llx_dalfred_token_usage (
    rowid              INT AUTO_INCREMENT PRIMARY KEY,
    entity             INT NOT NULL DEFAULT 1,
    fk_user            INT NOT NULL,
    thread_id          VARCHAR(64) NOT NULL,
    model              VARCHAR(100) NOT NULL,
    provider           VARCHAR(50) NOT NULL,
    input_tokens       INT NOT NULL DEFAULT 0,
    output_tokens      INT NOT NULL DEFAULT 0,
    duration_ms        INT NOT NULL DEFAULT 0,
    tool_calls_count   SMALLINT NOT NULL DEFAULT 0,
    context_window     INT NOT NULL DEFAULT 0,
    date_creation      DATETIME NOT NULL,
    tms                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_dalfred_usage_thread (thread_id),
    INDEX idx_dalfred_usage_user_date (fk_user, date_creation),
    INDEX idx_dalfred_usage_entity_date (entity, date_creation),
    INDEX idx_dalfred_usage_model (model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
