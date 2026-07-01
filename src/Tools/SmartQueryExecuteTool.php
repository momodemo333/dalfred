<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SmartQueryExecuteTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'smart_query_execute',
            description: 'Exécuter une requête Smart Query sauvegardée par son ID. Fournir les valeurs des paramètres si la requête en contient.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'query_id',
                type: PropertyType::INTEGER,
                description: 'ID de la Smart Query à exécuter',
                required: true,
            ),
            ToolProperty::make(
                name: 'parameters',
                type: PropertyType::STRING,
                description: 'JSON des valeurs des paramètres : {"date_start":"2025-01-01","min_amount":"1000"}',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Nombre max de lignes (défaut: 50, max: 10000)',
                required: false,
            ),
        ];
    }

    public function __invoke(int $query_id, ?string $parameters = null, ?int $limit = null): string
    {
        $limit = min($limit ?? 50, 10000);

        // Fetch the query
        $sql = "SELECT rowid, fk_user, title, sql_query, parameters, scope"
            . " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " WHERE rowid = " . (int) $query_id
            . " AND entity = " . (int) $this->entityId
            . " AND (fk_user = " . (int) $this->userId . " OR scope = 'shared')";

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            return json_encode([
                'success' => false,
                'error' => 'Requête non trouvée ou accès refusé.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $queryObj = $this->db->fetch_object($resql);
        $sqlQuery = $queryObj->sql_query;

        // Substitute parameters
        $paramValues = [];
        if ($parameters) {
            $paramValues = json_decode($parameters, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return json_encode([
                    'success' => false,
                    'error' => 'JSON des paramètres invalide: ' . json_last_error_msg(),
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        // Replace {{param_name}} with escaped and quoted values for SQL injection protection
        $sqlQuery = preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($paramValues) {
            $paramName = $matches[1];
            if (isset($paramValues[$paramName])) {
                return "'" . $this->db->escape($paramValues[$paramName]) . "'";
            }
            return $matches[0]; // Leave unchanged if not provided
        }, $sqlQuery);

        // Check for remaining unsubstituted parameters
        if (preg_match('/\{\{(\w+)\}\}/', $sqlQuery, $remaining)) {
            return json_encode([
                'success' => false,
                'error' => "Paramètre manquant : {$remaining[1]}. Fournis les valeurs des paramètres.",
            ], JSON_UNESCAPED_UNICODE);
        }

        // Re-validate SQL after substitution
        $validation = $this->validateSQL($sqlQuery);
        if (!$validation['valid']) {
            return json_encode([
                'success' => false,
                'error' => $validation['error'],
            ], JSON_UNESCAPED_UNICODE);
        }

        // Add LIMIT if not already present
        if (!preg_match('/\bLIMIT\b/i', $sqlQuery)) {
            $sqlQuery .= " LIMIT " . (int) $limit;
        }

        // Execute with timeout
        $startTime = microtime(true);
        $resql = $this->db->query($sqlQuery);
        $executionTime = round(microtime(true) - $startTime, 3);

        if (!$resql) {
            dol_syslog("SmartQuery execute error for id=$query_id: " . $this->db->lasterror(), LOG_ERR);
            return json_encode([
                'success' => false,
                'error' => 'Erreur lors de l\'exécution de la requête. Vérifie la syntaxe SQL.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $rows = [];
        $columns = [];
        $rowCount = 0;

        while ($row = $this->db->fetch_array($resql)) {
            if (empty($columns)) {
                $columns = array_keys($row);
                // Filter numeric keys (keep only named columns)
                $columns = array_filter($columns, function ($k) {
                    return !is_int($k);
                });
            }

            $cleanRow = [];
            foreach ($columns as $col) {
                $cleanRow[$col] = $row[$col];
            }
            $rows[] = $cleanRow;
            $rowCount++;

            // Safety limit
            if ($rowCount >= $limit) {
                break;
            }
        }

        // Update execution stats
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " SET execution_count = execution_count + 1, last_execution = NOW()"
            . " WHERE rowid = " . (int) $query_id
        );

        // Format result
        $totalRows = $this->db->num_rows($resql);
        $result = [
            'success' => true,
            'query_title' => $queryObj->title,
            'query_id' => (int) $query_id,
            'columns' => array_values($columns),
            'row_count' => $rowCount,
            'total_rows' => $totalRows,
            'execution_time' => $executionTime . 's',
            'rows' => $rows,
        ];

        // Truncate if too large
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return json_encode(['success' => false, 'error' => 'Failed to encode query result: ' . json_last_error_msg()]);
        }
        if (strlen($json) > 8000) {
            // Reduce rows to fit
            $maxRows = max(10, (int) ($rowCount * 8000 / strlen($json)));
            $result['rows'] = array_slice($rows, 0, $maxRows);
            $result['row_count'] = $maxRows;
            $result['truncated'] = true;
            $result['message'] = "Résultats tronqués à {$maxRows} lignes. Total : {$totalRows}. Utilise la page Smart Queries pour voir tous les résultats.";
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return json_encode(['success' => false, 'error' => 'Failed to encode query result: ' . json_last_error_msg()]);
        }
        return $encoded;
    }

    protected function validateSQL(string $sql): array
    {
        $trimmed = trim($sql);

        if (!preg_match('/^\s*SELECT\b/i', $trimmed)) {
            return ['valid' => false, 'error' => 'Seules les requêtes SELECT sont autorisées.'];
        }

        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
            'GRANT', 'REVOKE', 'EXEC\b', 'EXECUTE', 'INTO\s+OUTFILE', 'INTO\s+DUMPFILE',
            'LOAD_FILE', 'BENCHMARK', 'SLEEP', 'UNION', 'CALL', 'PROCEDURE',
        ];

        foreach ($forbidden as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $trimmed)) {
                return ['valid' => false, 'error' => "Mot-clé interdit : {$keyword}"];
            }
        }

        return ['valid' => true];
    }
}
