<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SmartQueryListTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'smart_query_list',
            description: 'Lister les requêtes Smart Query sauvegardées de l\'utilisateur. Retourne les titres, catégories et statistiques d\'exécution.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Filtrer par catégorie (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Rechercher dans le titre et la description (optionnel)',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $category = null, ?string $search = null): string
    {
        $sql = "SELECT rowid, title, description, category, scope, parameters,"
            . " execution_count, last_execution, date_creation"
            . " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " WHERE entity = " . (int) $this->entityId
            . " AND (fk_user = " . (int) $this->userId . " OR scope = 'shared')";

        if ($category) {
            $sql .= " AND category = '" . $this->db->escape($category) . "'";
        }

        if ($search) {
            $sql .= " AND (title LIKE '%" . $this->db->escape($search) . "%'"
                . " OR description LIKE '%" . $this->db->escape($search) . "%')";
        }

        $sql .= " ORDER BY last_execution DESC, date_creation DESC";
        $sql .= " LIMIT 50";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return json_encode([
                'success' => false,
                'error' => 'Erreur lors de la récupération des requêtes',
            ], JSON_UNESCAPED_UNICODE);
        }

        $queries = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $hasParams = !empty($obj->parameters);
            $queries[] = [
                'id' => (int) $obj->rowid,
                'title' => $obj->title,
                'description' => $obj->description,
                'category' => $obj->category,
                'scope' => $obj->scope,
                'has_parameters' => $hasParams,
                'execution_count' => (int) $obj->execution_count,
                'last_execution' => $obj->last_execution,
                'date_creation' => $obj->date_creation,
            ];
        }

        return json_encode([
            'success' => true,
            'count' => count($queries),
            'queries' => $queries,
        ], JSON_UNESCAPED_UNICODE);
    }
}
