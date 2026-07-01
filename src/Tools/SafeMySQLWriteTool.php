<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use PDO;
use Throwable;

/**
 * SafeMySQLWriteTool — see SafeMySQLSelectTool for rationale.
 */
class SafeMySQLWriteTool extends MySQLWriteTool
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @param array<array{name: string, value: string}>|null $parameters
     */
    public function __invoke(string $query, ?array $parameters = []): string
    {
        try {
            return parent::__invoke($query, $parameters);
        } catch (Throwable $e) {
            return 'SQL execution failed: ' . $e->getMessage()
                . "\nQuery: " . $query;
        }
    }
}
