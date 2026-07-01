<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * AsyncResponseService - Manages asynchronous chat response state
 *
 * Handles the fire-and-forget pattern: marks threads as "processing",
 * detects when responses are ready, and manages stale/error states.
 */
class AsyncResponseService
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    /** Seconds before a pending response is considered stale */
    private const STALE_TIMEOUT = 300; // 5 minutes

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    /**
     * Mark a thread as currently processing an LLM response
     */
    public function markProcessing(string $threadId): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET pending_response = 1,
                    pending_since = '" . date('Y-m-d H:i:s') . "',
                    pending_error = NULL
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $this->db->query($sql);
        dol_syslog('[Dalfred] Async: markProcessing thread=' . $threadId, LOG_INFO);
    }

    /**
     * Mark a thread as complete (response received, reset pending state)
     */
    public function markComplete(string $threadId): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET pending_response = 0,
                    pending_since = NULL,
                    pending_error = NULL
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $this->db->query($sql);
        dol_syslog('[Dalfred] Async: markComplete thread=' . $threadId, LOG_INFO);
    }

    /**
     * Mark a thread with an error (LLM call failed)
     */
    public function markError(string $threadId, string $error): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_threads
                SET pending_response = 0,
                    pending_since = NULL,
                    pending_error = '" . $this->db->escape($error) . "'
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $this->db->query($sql);
        dol_syslog('[Dalfred] Async: markError thread=' . $threadId . ' error=' . $error, LOG_WARNING);
    }

    /**
     * Reset a stale processing state (thread stuck > STALE_TIMEOUT seconds)
     *
     * @return bool True if the thread was stale and has been reset
     */
    public function resetIfStale(string $threadId): bool
    {
        $state = $this->getPendingState($threadId);

        if (!$state['pending'] || empty($state['since'])) {
            return false;
        }

        $pendingSince = strtotime($state['since']);
        if ($pendingSince === false) {
            return false;
        }

        if ((time() - $pendingSince) > self::STALE_TIMEOUT) {
            $this->markError($threadId, 'Le traitement a expiré (timeout). Veuillez réessayer.');
            dol_syslog('[Dalfred] Async: resetIfStale thread=' . $threadId . ' was stale since ' . $state['since'], LOG_WARNING);
            return true;
        }

        return false;
    }

    /**
     * Get the pending state of a thread
     *
     * @return array{pending: bool, since: string|null, error: string|null}
     */
    public function getPendingState(string $threadId): array
    {
        $sql = "SELECT pending_response, pending_since, pending_error
                FROM " . MAIN_DB_PREFIX . "dalfred_threads
                WHERE thread_id = '" . $this->db->escape($threadId) . "'
                AND fk_user = " . (int)$this->userId . "
                AND entity = " . (int)$this->entityId;

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $row = $this->db->fetch_object($result);
            return [
                'pending' => (bool)(int)$row->pending_response,
                'since' => $row->pending_since,
                'error' => $row->pending_error,
            ];
        }

        return ['pending' => false, 'since' => null, 'error' => null];
    }

    /**
     * Check if the thread is currently processing (to block double-submit)
     */
    public function isProcessing(string $threadId): bool
    {
        $state = $this->getPendingState($threadId);
        return $state['pending'];
    }

    /**
     * Detect if the server environment supports fire-and-forget
     *
     * Checks for fastcgi_finish_request() (PHP-FPM) or ability to use
     * Connection: close header trick (Apache mod_php, etc.)
     */
    public function canRunInBackground(): bool
    {
        // Respect admin override
        if (getDolGlobalInt('DALFRED_ASYNC_DISABLED')) {
            return false;
        }

        // Windows/XAMPP: async background processing is unreliable
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        // PHP-FPM: best option
        if (function_exists('fastcgi_finish_request')) {
            return true;
        }

        // Apache/mod_php: Connection: close trick works
        // We check if headers haven't been sent yet (they shouldn't at this point)
        if (!headers_sent()) {
            return true;
        }

        return false;
    }

    /**
     * Close the HTTP connection and continue PHP execution in background
     *
     * Sends the JSON response to the client immediately, then allows
     * the PHP script to continue processing (LLM call) without the
     * client waiting.
     *
     * @param array $jsonResponse The response to send to the client
     */
    public function closeConnectionAndContinue(array $jsonResponse): void
    {
        $output = json_encode($jsonResponse);

        // PHP-FPM: use fastcgi_finish_request() — cleanest method
        if (function_exists('fastcgi_finish_request')) {
            echo $output;
            fastcgi_finish_request();
            return;
        }

        // Apache/mod_php fallback: Connection: close + Content-Length
        // This tells the client the response is complete while PHP continues
        ignore_user_abort(true);

        // Replace headers (Content-Type already set at top of chat.php)
        header('Connection: close');
        header('Content-Length: ' . strlen($output));

        echo $output;

        // Flush all output buffers
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
