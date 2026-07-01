<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PDO;

/**
 * Drop-in replacement for NeuronAI's MySQLToolkit using the Safe* variants
 * which guarantee Tool::$result is always populated, even on PDO failures.
 *
 * Supports three independent capabilities (schema introspection, SELECT,
 * write) so the admin can grant the cheap, low-risk schema tool on its own
 * without exposing row data. Set the relevant constructor flag(s) to control
 * which tools are returned by provide().
 */
class SafeMySQLToolkit extends AbstractToolkit
{
    /**
     * @param PDO  $pdo            Live PDO handle bound to the Dolibarr DB.
     * @param bool $enableSchema   Include SafeMySQLSchemaTool (DESCRIBE / SHOW CREATE).
     * @param bool $enableSelect   Include SafeMySQLSelectTool (SELECT ... FROM ...).
     * @param bool $enableWrite    Include SafeMySQLWriteTool (INSERT/UPDATE/DELETE).
     */
    public function __construct(
        protected PDO $pdo,
        protected bool $enableSchema = true,
        protected bool $enableSelect = true,
        protected bool $enableWrite = true
    ) {
    }

    public function guidelines(): ?string
    {
        return "These tools allow you to learn the database structure,
        getting detailed information about tables, columns, relationships, and constraints
        to generate and execute precise and efficient SQL queries for MySQL database.";
    }

    public function provide(): array
    {
        $tools = [];
        if ($this->enableSchema) {
            $tools[] = SafeMySQLSchemaTool::make($this->pdo);
        }
        if ($this->enableSelect) {
            $tools[] = SafeMySQLSelectTool::make($this->pdo);
        }
        if ($this->enableWrite) {
            $tools[] = SafeMySQLWriteTool::make($this->pdo);
        }
        return $tools;
    }
}
