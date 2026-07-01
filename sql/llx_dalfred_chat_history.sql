-- ============================================================================
-- Dalfred Module - Chat History table (NeuronAI compatible)
-- ============================================================================
-- This table stores messages in JSON format as required by NeuronAI's SQLChatHistory
-- The messages column contains serialized Message objects including:
-- - UserMessage, AssistantMessage
-- - ToolCallMessage, ToolCallResultMessage
-- - Usage information, attachments, metadata

CREATE TABLE IF NOT EXISTS llx_dalfred_chat_history (
    thread_id       VARCHAR(64) NOT NULL PRIMARY KEY,
    messages        LONGTEXT NOT NULL,

    INDEX idx_dalfred_history_thread (thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
