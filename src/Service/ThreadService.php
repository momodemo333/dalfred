<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * ThreadService - Manages conversation threads per user
 *
 * This service handles the metadata for conversations while NeuronAI's
 * SQLChatHistory handles the actual message storage.
 */
class ThreadService
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    /**
     * Create a new thread for the user
     *
     * @param string|null $title Optional title for the conversation
     * @param string|null $contextPage Optional context page (e.g., "invoice/card.php?id=123")
     * @return string The generated thread_id
     */
    public function createThread(?string $title = null, ?string $contextPage = null): string
    {
        $threadId = $this->generateThreadId();
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_threads
                (entity, fk_user, thread_id, title, status, context_page, date_creation, date_last_message)
                VALUES (
                    " . (int)$this->entityId . ",
                    " . (int)$this->userId . ",
                    '" . $this->db->escape($threadId) . "',
                    " . ($title ? "'" . $this->db->escape($title) . "'" : "NULL") . ",
                    'active',
                    " . ($contextPage ? "'" . $this->db->escape($contextPage) . "'" : "NULL") . ",
                    '" . $now . "',
                    '" . $now . "'
                )";

        $result = $this->db->query($sql);
        if (!$result) {
            throw new \RuntimeException('Failed to create thread: ' . $this->db->lasterror());
        }

        return $threadId;
    }

    /**
     * Get an existing thread or create a new one
     *
     * @param string|null $threadId Optional specific thread ID to retrieve
     * @return string The thread_id
     */
    public function getOrCreateThread(?string $threadId = null): string
    {
        if ($threadId) {
            // Check if thread exists and belongs to user
            if ($this->threadExists($threadId)) {
                return $threadId;
            }
            throw new \RuntimeException('Thread not found or access denied');
        }

        // Get most recent active thread for user
        $sql = "SELECT thread_id FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId . "
                AND status = 'active'
                ORDER BY date_last_message DESC
                LIMIT 1";

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            return $row->thread_id;
        }

        // No active thread found, create new one
        return $this->createThread();
    }

    /**
     * Check if a thread exists and belongs to the current user
     */
    public function threadExists(string $threadId): bool
    {
        $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $result = $this->db->query($sql);
        return $result && $this->db->num_rows($result) > 0;
    }

    /**
     * Update thread's last message timestamp
     */
    public function updateLastMessageTime(string $threadId): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET date_last_message = '" . date('Y-m-d H:i:s') . "'
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $this->db->query($sql);
    }

    /**
     * Update thread title
     */
    public function updateTitle(string $threadId, string $title): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET title = '" . $this->db->escape($title) . "'
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $this->db->query($sql);
    }

    /**
     * Close/archive a thread
     */
    public function closeThread(string $threadId): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET status = 'closed'
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $this->db->query($sql);
    }

    /**
     * Get user's threads (for listing in UI)
     *
     * @param int $limit Maximum number of threads to return
     * @param string $status Filter by status ('active', 'closed', or 'all')
     * @return array List of threads with metadata
     */
    public function getUserThreads(int $limit = 20, string $status = 'all'): array
    {
        $sql = "SELECT thread_id, title, status, context_page, date_creation, date_last_message
                FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        if ($status !== 'all') {
            $sql .= " AND status = '" . $this->db->escape($status) . "'";
        }

        $sql .= " ORDER BY date_last_message DESC LIMIT " . (int)$limit;

        $result = $this->db->query($sql);
        $threads = [];

        if ($result) {
            while ($row = $this->db->fetch_object($result)) {
                $threads[] = [
                    'thread_id' => $row->thread_id,
                    'title' => $row->title,
                    'status' => $row->status,
                    'context_page' => $row->context_page,
                    'date_creation' => $row->date_creation,
                    'date_last_message' => $row->date_last_message,
                ];
            }
        }

        return $threads;
    }

    /**
     * Get user's most recent active thread
     * This is used to restore the conversation after page refresh
     *
     * @return array|null Thread data or null if no active thread
     */
    public function getActiveThread(): ?array
    {
        $sql = "SELECT thread_id, title, status, context_page, date_creation, date_last_message
                FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId . "
                AND status = 'active'
                ORDER BY date_last_message DESC
                LIMIT 1";

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            return [
                'thread_id' => $row->thread_id,
                'title' => $row->title,
                'status' => $row->status,
                'context_page' => $row->context_page,
                'created_at' => $row->date_creation,
                'last_message_at' => $row->date_last_message,
            ];
        }

        return null;
    }

    /**
     * Archive a thread (set status to closed)
     *
     * @param string $threadId Thread ID to archive
     * @return bool Success status
     */
    public function archiveThread(string $threadId): bool
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET status = 'archived'
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        return (bool)$this->db->query($sql);
    }

    /**
     * Reopen/activate a thread
     *
     * @param string $threadId Thread ID to reopen
     * @return bool Success status
     */
    public function reopenThread(string $threadId): bool
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET status = 'active'
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        return (bool)$this->db->query($sql);
    }

    /**
     * Get messages for a thread from NeuronAI's SQLChatHistory table
     *
     * @param string $threadId Thread ID
     * @return array Messages list
     */
    public function getThreadMessages(string $threadId): array
    {
        // First verify thread belongs to user
        if (!$this->threadExists($threadId)) {
            return [];
        }

        $sql = "SELECT messages FROM " . MAIN_DB_PREFIX . "dalfred_chat_history
                WHERE thread_id = '" . $this->db->escape($threadId) . "'";

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            $messages = json_decode($row->messages, true);

            if (is_array($messages)) {
                // Format messages for display
                $formattedMessages = [];
                foreach ($messages as $msg) {
                    // Skip tool call messages (both tool_call and tool_call_result types)
                    if (isset($msg['type']) && in_array($msg['type'], ['tool_call', 'tool_call_result'], true)) {
                        continue;
                    }

                    // Skip messages with tool role
                    if (isset($msg['role']) && in_array($msg['role'], ['tool', 'tool_result'], true)) {
                        continue;
                    }

                    // Normalize content: NeuronAI v3 serializes it as an array of
                    // ContentBlocks (e.g. [{type:"text",content:"..."}]) while v2 used a
                    // plain string. We flatten to a single string so the chat UI stays
                    // simple and backward-compatible with legacy v2 threads.
                    $content = self::flattenContent($msg['content'] ?? null);
                    if ($content === '') {
                        continue;
                    }

                    // NeuronAI stores messages with 'role' and 'content' keys
                    if (isset($msg['role'])) {
                        $role = $msg['role'];
                        // Normalize role names
                        if ($role === 'human' || $role === 'user') {
                            $role = 'user';
                        } elseif ($role === 'ai' || $role === 'assistant') {
                            $role = 'assistant';
                        }

                        $attachments = self::extractAttachments($msg['content'] ?? null, $_SESSION['token'] ?? '');
                        $formattedMessages[] = [
                            'role' => $role,
                            'content' => $content,
                            'created_at' => $msg['created_at'] ?? date('Y-m-d H:i:s'),
                            'attachments' => $attachments,
                        ];
                    }
                }
                return $formattedMessages;
            }
        }

        return [];
    }

    /**
     * Get thread details
     */
    public function getThread(string $threadId): ?array
    {
        $sql = "SELECT rowid, thread_id, title, status, context_page, date_creation, date_last_message
                FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            return [
                'rowid' => (int)$row->rowid,
                'thread_id' => $row->thread_id,
                'title' => $row->title,
                'status' => $row->status,
                'context_page' => $row->context_page,
                'date_creation' => $row->date_creation,
                'date_last_message' => $row->date_last_message,
            ];
        }

        return null;
    }

    /**
     * Delete a thread and its chat history
     */
    public function deleteThread(string $threadId): bool
    {
        // First delete from chat_history
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_chat_history
                WHERE thread_id = '" . $this->db->escape($threadId) . "'";
        $this->db->query($sql);

        // Then delete from threads
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        return (bool)$this->db->query($sql);
    }

    /**
     * Generate a unique thread ID
     */
    protected function generateThreadId(): string
    {
        return sprintf(
            'thread_%d_%s_%s',
            $this->userId,
            date('Ymd_His'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Get PDO connection from DoliDB for use with NeuronAI's SQLChatHistory
     */
    public function getPDO(): \PDO
    {
        // Get connection details from Dolibarr
        global $dolibarr_main_db_host, $dolibarr_main_db_name, $dolibarr_main_db_user, $dolibarr_main_db_pass, $dolibarr_main_db_port;

        $port = !empty($dolibarr_main_db_port) ? (int)$dolibarr_main_db_port : 3306;

        $dsn = "mysql:host={$dolibarr_main_db_host};port={$port};dbname={$dolibarr_main_db_name};charset=utf8mb4";

        return new \PDO($dsn, $dolibarr_main_db_user, $dolibarr_main_db_pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Flatten a message content payload to a plain string.
     *
     * NeuronAI v3 serializes message content as an array of ContentBlocks
     * (e.g. [{type:"text",content:"..."}, {type:"image",...}]); v2 stored it
     * as a plain string. The chat UI expects a string, so we concatenate all
     * text blocks and drop media blocks (which the chat can't render anyway).
     *
     * @param mixed $content String, array of blocks, or null.
     */
    /**
     * Extract attachment metadata from a serialized message content payload.
     *
     * Looks for entries shaped like AttachmentMetaContent::toArray() and returns
     * a frontend-friendly array with 'expired' computed from the file presence.
     *
     * @param mixed $content
     * @return array<int, array{type:string, kind:string, name:string, mime:string, size:int, url:string, expired:bool}>
     */
    private static function extractAttachments($content, string $token): array
    {
        if (!is_array($content)) {
            return [];
        }
        $out = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) !== 'dalfred_attachment_meta') {
                continue;
            }

            $relativePath = (string) ($block['path'] ?? '');
            $abs = FileAttachmentService::storageRoot() . '/' . $relativePath;
            $expired = !is_file($abs);

            $out[] = [
                'type'    => 'attachment',
                'kind'    => (string) ($block['kind'] ?? 'text'),
                'name'    => (string) ($block['originalName'] ?? '?'),
                'mime'    => (string) ($block['mimeType'] ?? 'application/octet-stream'),
                'size'    => (int)    ($block['sizeBytes'] ?? 0),
                'url'     => $expired
                    ? ''
                    : dol_buildpath('/dalfred/ajax/attachment.php?path=' . rawurlencode($relativePath) . '&token=' . rawurlencode($token), 1),
                'expired' => $expired,
            ];
        }
        return $out;
    }

    private static function flattenContent($content): string
    {
        if ($content === null || $content === '') {
            return '';
        }
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_string($block)) {
                    $parts[] = $block;
                } elseif (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['content'] ?? null)) {
                    $parts[] = $block['content'];
                }
            }
            return implode("\n", $parts);
        }
        return '';
    }
}
