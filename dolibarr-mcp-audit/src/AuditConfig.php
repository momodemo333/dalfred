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
 * \file    src/AuditConfig.php
 * \ingroup dolibarr-mcp-audit
 * \brief   Per-host-module parameterization.
 *
 * Same arrangement as dolibarr-mcp-sql: emMCP and Dalfred can be installed on
 * the same Dolibarr, so each owns its table and its constants and nothing is
 * shared at runtime.
 */
final class AuditConfig
{
    /**
     * @param string $module      module slug, e.g. 'dalfred'
     * @param string $tablePrefix table prefix without MAIN_DB_PREFIX, e.g. 'dalfred_'
     * @param string $constPrefix constant prefix, e.g. 'DALFRED'
     */
    public function __construct(
        public readonly string $module,
        public readonly string $tablePrefix,
        public readonly string $constPrefix,
    ) {
    }

    /** e.g. const('RATE_LIMIT') === 'DALFRED_MCP_RATE_LIMIT' */
    public function const(string $suffix): string
    {
        return $this->constPrefix . '_MCP_' . $suffix;
    }

    /** e.g. 'llx_dalfred_mcp_log' */
    public function table(): string
    {
        return MAIN_DB_PREFIX . $this->tablePrefix . 'mcp_log';
    }

    /** e.g. '[DALFRED]' */
    public function logPrefix(): string
    {
        return '[' . $this->constPrefix . ']';
    }
}
