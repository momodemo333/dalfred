<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;
use Throwable;

/**
 * SafeMySQLSelectTool wraps the upstream NeuronAI MySQLSelectTool so that any
 * exception raised by PDO (syntax error, unknown table, etc.) is converted into
 * an error string that the LLM can read back. Without this, an uncaught
 * PDOException bubbles up before setResult() is called, leaving the underlying
 * Tool::$result null and triggering a fatal TypeError later in getResult()
 * when MessageMapper / TokenCounter / HandleToolEvents try to read it.
 */
class SafeMySQLSelectTool extends MySQLSelectTool
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @param array<array{name: string, value: string}>|null $parameters
     * @return string|array<int, array<string, mixed>>
     */
    public function __invoke(string $query, ?array $parameters = []): string|array
    {
        try {
            return parent::__invoke($query, $parameters);
        } catch (Throwable $e) {
            return 'SQL execution failed: ' . $e->getMessage()
                . "\nQuery: " . $query
                . "\nHint: verify the table/column names exist and that reserved keywords are wrapped in backticks.";
        }
    }
}
