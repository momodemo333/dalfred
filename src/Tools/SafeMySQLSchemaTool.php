<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use PDO;
use Throwable;

/**
 * SafeMySQLSchemaTool — see SafeMySQLSelectTool for rationale.
 */
class SafeMySQLSchemaTool extends MySQLSchemaTool
{
    public function __construct(PDO $pdo, ?array $tables = null)
    {
        parent::__construct($pdo, $tables);
    }

    public function __invoke(): string
    {
        try {
            return parent::__invoke();
        } catch (Throwable $e) {
            return 'Schema analysis failed: ' . $e->getMessage();
        }
    }
}
