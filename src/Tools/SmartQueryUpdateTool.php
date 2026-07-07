<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SmartQueryUpdateTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'smart_query_update',
            description: 'Modifier une requête Smart Query existante (titre, SQL, description, catégorie, portée private/shared). Seul le propriétaire peut modifier.',
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
                description: 'ID de la Smart Query à modifier',
                required: true,
            ),
            ToolProperty::make(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Nouveau titre (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'sql_query',
                type: PropertyType::STRING,
                description: 'Nouvelle requête SQL (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Nouvelle description (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Nouvelle catégorie (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'parameters',
                type: PropertyType::STRING,
                description: 'Nouveau JSON des paramètres (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'Nouvelle portée : "private" (visible uniquement par le propriétaire) ou "shared" (visible par tous les utilisateurs). Optionnel.',
                required: false,
            ),
        ];
    }

    public function __invoke(int $query_id, ?string $title = null, ?string $sql_query = null, ?string $description = null, ?string $category = null, ?string $parameters = null, ?string $scope = null): string
    {
        // Check ownership
        $sql = "SELECT rowid, fk_user FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " WHERE rowid = " . (int) $query_id
            . " AND entity = " . (int) $this->entityId
            . " AND fk_user = " . (int) $this->userId;

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            return json_encode([
                'success' => false,
                'error' => 'Requête non trouvée ou tu n\'es pas le propriétaire.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Validate SQL if provided
        if ($sql_query) {
            $validation = $this->validateSQL($sql_query);
            if (!$validation['valid']) {
                return json_encode([
                    'success' => false,
                    'error' => $validation['error'],
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        // Validate parameters JSON if provided
        if ($parameters) {
            $decoded = json_decode($parameters, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return json_encode([
                    'success' => false,
                    'error' => 'Le JSON des paramètres est invalide: ' . json_last_error_msg(),
                ], JSON_UNESCAPED_UNICODE);
            }
            $parameters = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        // Build update
        $sets = [];
        if ($title !== null) {
            $sets[] = "title = '" . $this->db->escape($title) . "'";
        }
        if ($sql_query !== null) {
            $sets[] = "sql_query = '" . $this->db->escape($sql_query) . "'";
        }
        if ($description !== null) {
            $sets[] = "description = '" . $this->db->escape($description) . "'";
        }
        if ($category !== null) {
            $sets[] = "category = '" . $this->db->escape($category) . "'";
        }
        if ($parameters !== null) {
            $sets[] = "parameters = '" . $this->db->escape($parameters) . "'";
        }
        if ($scope !== null) {
            if (!in_array($scope, ['private', 'shared'], true)) {
                return json_encode([
                    'success' => false,
                    'error' => 'Portée invalide : utilise "private" ou "shared".',
                ], JSON_UNESCAPED_UNICODE);
            }
            $sets[] = "scope = '" . $this->db->escape($scope) . "'";
        }

        if (empty($sets)) {
            return json_encode([
                'success' => false,
                'error' => 'Aucune modification fournie.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " SET " . implode(', ', $sets)
            . " WHERE rowid = " . (int) $query_id;

        $result = $this->db->query($sql);
        if ($result) {
            return json_encode([
                'success' => true,
                'message' => "Requête #{$query_id} mise à jour.",
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => false,
            'error' => 'Erreur lors de la mise à jour',
        ], JSON_UNESCAPED_UNICODE);
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
