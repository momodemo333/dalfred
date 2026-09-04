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
 * \file    src/Decision.php
 * \ingroup dolibarr-mcp-audit
 * \brief   Outcome of a rate-limit check, with the numbers behind it.
 *
 * Carries the counts so the refusal message can tell the agent what actually
 * happened — "58 of 60 calls in the last 60 minutes" is something a model can
 * act on; "rate limit exceeded" leaves it retrying blindly.
 */
final class Decision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly int $used,
        public readonly int $limit,
        public readonly int $windowMinutes,
    ) {
    }

    public static function allowed(int $used, int $limit, int $windowMinutes): self
    {
        return new self(true, $used, $limit, $windowMinutes);
    }

    public static function refused(int $used, int $limit, int $windowMinutes): self
    {
        return new self(false, $used, $limit, $windowMinutes);
    }

    /** Human-readable reason, safe to hand back to the caller. */
    public function message(): string
    {
        return sprintf(
            'Rate limit reached: %d calls in the last %d minutes, the limit is %d. '
                . 'Wait before calling again, or ask an administrator to raise the limit.',
            $this->used,
            $this->windowMinutes,
            $this->limit
        );
    }
}
