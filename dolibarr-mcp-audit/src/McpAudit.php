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

/**
 * \file    src/McpAudit.php
 * \ingroup dolibarr-mcp-audit
 * \brief   The one object an MCP entry point talks to.
 *
 * Wraps the log, the limiter and the alert so mcp.php reads as three lines
 * rather than fifteen — and so the ordering that matters is decided here once
 * instead of being re-derived in every host module:
 *
 *   1. the limit is checked BEFORE the call runs, otherwise it caps nothing;
 *   2. the call is recorded whether it succeeded or not, because a refused
 *      call is exactly what an audit trail is for;
 *   3. the alert is evaluated AFTER recording, so the count it reads includes
 *      the call that may have crossed the threshold.
 */
final class McpAudit
{
    /** @var \DoliDB */
    private $db;

    private AuditConfig $config;

    private CallLog $log;

    private RateLimiter $limiter;

    private AlertNotifier $notifier;

    /**
     * @param \DoliDB $db Database handler
     */
    public function __construct($db, AuditConfig $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->log = new CallLog($db, $config);
        $this->limiter = new RateLimiter($this->log, $config);
        $this->notifier = new AlertNotifier($db, $this->log, $config);
    }

    /**
     * May this user call right now?
     *
     * Call before dispatching. A refusal should be recorded too — pass the
     * decision to record() so the trail shows the attempt.
     */
    public function check(int $userId): Decision
    {
        return $this->limiter->check($userId);
    }

    /**
     * Record one call and, if it stands out, warn an administrator.
     *
     * @param int         $userId     Dolibarr user the agent acted as
     * @param string      $login      That user's login
     * @param string      $method     JSON-RPC method
     * @param string|null $tool       Tool name for tools/call
     * @param array|null  $arguments  Tool arguments
     * @param int         $durationMs Wall-clock duration
     * @param bool        $success    Outcome
     * @param string|null $error      Short reason when it failed
     * @param string|null $client     Client name from initialize
     */
    public function record(
        int $userId,
        string $login,
        string $method,
        ?string $tool,
        ?array $arguments,
        int $durationMs,
        bool $success,
        ?string $error = null,
        ?string $client = null
    ): void {
        $this->log->record($userId, $method, $tool, $arguments, $durationMs, $success, $error, $client);

        // Only tool calls count towards unusual activity. Protocol chatter —
        // initialize, tools/list, notifications — says nothing about how much
        // data someone is pulling, and counting it would make the threshold
        // fire on clients that merely reconnect often.
        if ($method === 'tools/call') {
            $this->notifier->notifyIfUnusual($userId, $login, $this->limiter->windowMinutes());
        }
    }

    /** Remove rows past the configured retention. */
    public function purge(): int
    {
        return $this->log->purge();
    }

    public function config(): AuditConfig
    {
        return $this->config;
    }

    /**
     * Did this MCP response actually carry a failure?
     *
     * The transport answers 200 for a tool that failed: the error travels
     * inside the JSON-RPC payload, as a protocol-level "error" object or as a
     * tool result flagged isError. Judging success by the HTTP status alone
     * recorded a Dolibarr 403 as a successful call, which is exactly backwards
     * for the two things this log is for — telling support what went wrong, and
     * showing an administrator which reads actually returned data.
     *
     * @param  string $payload Response body, JSON or SSE
     * @return string|null     Short reason when it failed, null when it did not
     */
    public static function failureReason(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        // Streamable HTTP frames the JSON in SSE "data:" lines; a plain JSON
        // body has none, and passing it through this loop leaves it untouched.
        $chunks = [];
        foreach (explode("\n", $payload) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $chunks[] = trim(substr($line, 5));
            }
        }
        if ($chunks === []) {
            $chunks = [$payload];
        }

        foreach ($chunks as $chunk) {
            $decoded = json_decode($chunk, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                return $decoded['error']['message'];
            }

            if (($decoded['result']['isError'] ?? false) === true) {
                $text = $decoded['result']['content'][0]['text'] ?? 'tool reported an error';

                return is_string($text) ? $text : 'tool reported an error';
            }
        }

        return null;
    }

    /**
     * Pull the method, tool name and arguments out of a raw JSON-RPC body.
     *
     * Kept here so both host modules read a request the same way, and so a
     * malformed body degrades to "unknown" instead of throwing on the entry
     * point — the body is attacker-controlled and must never be trusted to
     * parse.
     *
     * @param  string $raw Request body
     * @return array{method: string, tool: string|null, arguments: array|null, client: string|null}
     */
    public static function describeRequest(string $raw): array
    {
        $blank = ['method' => 'unknown', 'tool' => null, 'arguments' => null, 'client' => null];

        if ($raw === '') {
            return $blank;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $blank;
        }

        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

        return [
            'method' => is_string($decoded['method'] ?? null) ? $decoded['method'] : 'unknown',
            'tool' => is_string($params['name'] ?? null) ? $params['name'] : null,
            'arguments' => is_array($params['arguments'] ?? null) ? $params['arguments'] : null,
            'client' => is_string($params['clientInfo']['name'] ?? null) ? $params['clientInfo']['name'] : null,
        ];
    }
}
