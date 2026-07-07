<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SmartQuerySaveTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'smart_query_save',
            description: 'Sauvegarder une requête SQL SELECT pour la réutiliser plus tard. Utilise cet outil quand l\'utilisateur veut sauvegarder une requête de données. La requête doit être SELECT uniquement.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Titre court et descriptif de la requête (ex: "Factures impayées > 30 jours")',
                required: true,
            ),
            ToolProperty::make(
                name: 'sql_query',
                type: PropertyType::STRING,
                description: 'Requête SQL SELECT à sauvegarder. Utiliser {{param_name}} pour les paramètres dynamiques.',
                required: true,
            ),
            ToolProperty::make(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Description en langage naturel de ce que fait la requête',
                required: false,
            ),
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Catégorie : clients, factures, commandes, produits, fournisseurs, comptabilite, rh, autre',
                required: false,
            ),
            ToolProperty::make(
                name: 'parameters',
                type: PropertyType::STRING,
                description: 'JSON des paramètres dynamiques: [{"name":"date_start","label":"Date début","type":"date","default":"","required":true}]. Types: date, number, text',
                required: false,
            ),
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'Portée : "private" (visible uniquement par le propriétaire, défaut) ou "shared" (visible par tous les utilisateurs).',
                required: false,
            ),
        ];
    }

    public function __invoke(string $title, string $sql_query, ?string $description = null, ?string $category = null, ?string $parameters = null, ?string $scope = null): string
    {
        $scopeValue = in_array($scope, ['private', 'shared'], true) ? $scope : 'private';

        // Validate SQL is SELECT only
        $validation = $this->validateSQL($sql_query);
        if (!$validation['valid']) {
            return json_encode([
                'success' => false,
                'error' => $validation['error'],
            ], JSON_UNESCAPED_UNICODE);
        }

        // Check max queries per user (100)
        $sql_count = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " WHERE fk_user = " . (int) $this->userId
            . " AND entity = " . (int) $this->entityId;
        $resql = $this->db->query($sql_count);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ((int) $obj->total >= 100) {
                return json_encode([
                    'success' => false,
                    'error' => 'Limite de 100 requêtes sauvegardées atteinte. Supprime des requêtes existantes avant d\'en créer de nouvelles.',
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

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " (entity, fk_user, title, description, sql_query, category, parameters, scope, date_creation)"
            . " VALUES ("
            . (int) $this->entityId . ", "
            . (int) $this->userId . ", "
            . "'" . $this->db->escape($title) . "', "
            . ($description ? "'" . $this->db->escape($description) . "'" : "NULL") . ", "
            . "'" . $this->db->escape($sql_query) . "', "
            . ($category ? "'" . $this->db->escape($category) . "'" : "NULL") . ", "
            . ($parameters ? "'" . $this->db->escape($parameters) . "'" : "NULL") . ", "
            . "'" . $this->db->escape($scopeValue) . "', "
            . "NOW()"
            . ")";

        $result = $this->db->query($sql);
        if ($result) {
            $id = $this->db->last_insert_id(MAIN_DB_PREFIX . 'dalfred_smart_queries');
            return json_encode([
                'success' => true,
                'id' => $id,
                'message' => "Requête sauvegardée : \"{$title}\" (ID: {$id}). L'utilisateur peut la retrouver dans Outils > Dalfred > Smart Queries.",
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => false,
            'error' => 'Erreur lors de la sauvegarde de la requête',
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function validateSQL(string $sql): array
    {
        $trimmed = trim($sql);

        // Must start with SELECT
        if (!preg_match('/^\s*SELECT\b/i', $trimmed)) {
            return ['valid' => false, 'error' => 'Seules les requêtes SELECT sont autorisées.'];
        }

        // Blacklisted keywords
        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
            'GRANT', 'REVOKE', 'EXEC\b', 'EXECUTE', 'INTO\s+OUTFILE', 'INTO\s+DUMPFILE',
            'LOAD_FILE', 'BENCHMARK', 'SLEEP', 'UNION', 'CALL', 'PROCEDURE',
        ];

        foreach ($forbidden as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $trimmed)) {
                return ['valid' => false, 'error' => "Mot-clé interdit détecté : {$keyword}. Seul SELECT est autorisé."];
            }
        }

        return ['valid' => true];
    }
}
