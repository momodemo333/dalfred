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
 * \file    src/RateLimiter.php
 * \ingroup dolibarr-mcp-audit
 * \brief   Caps how many MCP calls a user may make in a rolling window.
 *
 * What this is for, and what it is not. An administrator who opens MCP access
 * to employees keeps Dolibarr's permissions as the boundary of what each of
 * them may see — that part is unchanged. What permissions do not express is
 * *volume*: an employee entitled to read customers one at a time is not
 * thereby entitled to pull the entire customer base in an afternoon. This caps
 * the pace, so bulk extraction stops being quiet.
 *
 * It is deliberately a blunt instrument. It counts calls, not rows, because a
 * cap on rows would be trivially worked around by asking for them in smaller
 * pieces — which is exactly the pattern it is meant to make expensive.
 *
 * Off by default: a limit set without thought is a support ticket waiting to
 * happen, and the administrator is the only one who knows what normal use
 * looks like on their instance.
 */
class RateLimiter
{
    private const DEFAULT_WINDOW_MINUTES = 60;

    private CallLog $log;

    private AuditConfig $config;

    public function __construct(CallLog $log, AuditConfig $config)
    {
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * Is this user still allowed to call?
     *
     * @param  int $userId Dolibarr user the agent acts as
     * @return Decision
     */
    public function check(int $userId): Decision
    {
        $limit = \getDolGlobalInt($this->config->const('RATE_LIMIT'));
        if ($limit <= 0) {
            return Decision::allowed(0, 0, 0);
        }

        $window = $this->windowMinutes();
        $used = $this->log->countRecent($userId, $window);

        if ($used >= $limit) {
            return Decision::refused($used, $limit, $window);
        }

        return Decision::allowed($used, $limit, $window);
    }

    public function windowMinutes(): int
    {
        $window = \getDolGlobalInt($this->config->const('RATE_WINDOW'));

        return $window > 0 ? $window : self::DEFAULT_WINDOW_MINUTES;
    }
}
