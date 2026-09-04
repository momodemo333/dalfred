<?php
declare(strict_types=1);
namespace DolibarrMcpAudit;

/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

use Throwable;

/**
 * \file    src/CallLog.php
 * \ingroup dolibarr-mcp-audit
 * \brief   Records every MCP call, and answers "how many lately?".
 *
 * Two needs, one table. Support needs to know what an agent actually did when a
 * customer reports a problem; an administrator needs to know whether someone is
 * pulling far more data than their job requires. Both questions are answered by
 * the same rows, so the rate limiter counts here rather than keeping a second
 * store that could disagree with the trail.
 *
 * What is NOT recorded: tool results. They carry the business data itself, and
 * a log that copies the data it is meant to police is a second copy to protect.
 * Arguments are recorded because they are what makes a call auditable — "which
 * customer did it read?" — but truncated, and the whole column can be switched
 * off for installations where even that is too much.
 */
class CallLog
{
    private const MAX_ARGUMENTS_CHARS = 2000;

    /** @var \DoliDB */
    private $db;

    private AuditConfig $config;

    /**
     * @param \DoliDB $db Database handler
     */
    public function __construct($db, AuditConfig $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Record one call. Never raises: a call that succeeded must not be reported
     * as failed because the trail could not be written. Failures go to syslog,
     * where an administrator can still find them.
     *
     * @param int         $userId     Dolibarr user the agent acted as
     * @param string      $method     JSON-RPC method, e.g. 'tools/call'
     * @param string|null $tool       Tool name when the method is tools/call
     * @param array|null  $arguments  Tool arguments, truncated before storage
     * @param int         $durationMs Wall-clock duration
     * @param bool        $success    Whether the call completed
     * @param string|null $error      Short error description when it did not
     * @param string|null $client     Client name reported at initialize
     */
    public function record(
        int $userId,
        string $method,
        ?string $tool,
        ?array $arguments,
        int $durationMs,
        bool $success,
        ?string $error = null,
        ?string $client = null
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $sql = 'INSERT INTO ' . $this->config->table()
                . ' (entity, fk_user, date_creation, method, tool_name, arguments,'
                . ' duration_ms, success, error_message, client_name)'
                . ' VALUES (' . $this->entity() . ', ' . $userId . ", '" . $this->db->idate(dol_now()) . "', "
                . $this->quote($method, 64) . ', '
                . $this->quote($tool, 128) . ', '
                . $this->quote($this->renderArguments($arguments), self::MAX_ARGUMENTS_CHARS) . ', '
                . $durationMs . ', ' . ($success ? 1 : 0) . ', '
                . $this->quote($error, 255) . ', '
                . $this->quote($client, 128) . ')';

            $this->db->query($sql);
        } catch (Throwable $e) {
            \dol_syslog($this->config->logPrefix() . ' MCP call log failed: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * How many tool calls this user landed within the last $windowMinutes.
     *
     * Counts the trail itself rather than a separate counter: one source of
     * truth, and an administrator reading the log sees exactly the rows the
     * limit was computed from.
     *
     * Two things are deliberately excluded, both learned by watching the first
     * version behave badly:
     *
     *  - anything that is not a tool call. Protocol traffic — initialize,
     *    tools/list, notifications — moves no business data, and counting it
     *    meant a client that merely reconnects spent its quota before reading
     *    anything. With a limit of 3, the caller got exactly one real call.
     *  - calls that were themselves refused. Otherwise a client that retries
     *    deepens its own hole and the window never drains, turning a pause
     *    into a lockout.
     */
    public function countRecent(int $userId, int $windowMinutes): int
    {
        $since = dol_now() - ($windowMinutes * 60);

        $sql = 'SELECT COUNT(*) AS n FROM ' . $this->config->table()
            . ' WHERE entity = ' . $this->entity()
            . ' AND fk_user = ' . $userId
            . " AND method = 'tools/call'"
            . ' AND success = 1'
            . " AND date_creation >= '" . $this->db->idate($since) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            // Fail open on purpose: a broken count must not lock out a
            // legitimate user. The limit is a safeguard, not an access control
            // — that is what Dolibarr permissions are for.
            \dol_syslog($this->config->logPrefix() . ' MCP rate count failed', LOG_WARNING);

            return 0;
        }

        $obj = $this->db->fetch_object($resql);

        return $obj ? (int) $obj->n : 0;
    }

    /**
     * Delete rows older than the configured retention, in one statement.
     *
     * @return int Rows removed, or -1 when the statement failed
     */
    public function purge(): int
    {
        $days = (int) \getDolGlobalInt($this->config->const('LOG_RETENTION_DAYS'));
        if ($days <= 0) {
            $days = 90;
        }

        $cutoff = dol_now() - ($days * 86400);
        $sql = 'DELETE FROM ' . $this->config->table()
            . ' WHERE entity = ' . $this->entity()
            . " AND date_creation < '" . $this->db->idate($cutoff) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }

        return (int) $this->db->affected_rows($resql);
    }

    private function isEnabled(): bool
    {
        // Logging is on unless explicitly turned off: an MCP surface with no
        // trail is not something to end up with by omission.
        return \getDolGlobalString($this->config->const('LOG_ENABLED')) !== '0';
    }

    private function entity(): int
    {
        global $conf;

        return isset($conf->entity) ? (int) $conf->entity : 1;
    }

    /**
     * @param array<string, mixed>|null $arguments
     */
    private function renderArguments(?array $arguments): ?string
    {
        if ($arguments === null || $arguments === []) {
            return null;
        }

        // Recorded unless explicitly switched off, same as logging itself:
        // arguments are what makes a trail answer "which customer did it read?",
        // and a log that cannot answer that is not worth keeping.
        if (\getDolGlobalString($this->config->const('LOG_ARGUMENTS')) === '0') {
            return '[arguments not recorded by configuration]';
        }

        $json = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : null;
    }

    private function quote(?string $value, int $maxLength): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }

        return "'" . $this->db->escape(mb_substr($value, 0, $maxLength)) . "'";
    }
}
