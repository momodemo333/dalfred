<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class KnowledgeSearchTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'knowledge_search',
            description: 'Rechercher dans la mémoire persistante par mots-clés. Les mots sont combinés en OU (trouver au moins un mot suffit), les résultats sont triés par pertinence. Utilise cet outil quand tu cherches une information spécifique. Essaie plusieurs synonymes si la première recherche ne donne rien.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Mots-clés de recherche (séparés par des espaces, combinés en OU)',
                required: true,
            ),
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Filtrer par catégorie (optionnel)',
                required: false,
            ),
        ];
    }

    public function __invoke(string $query, ?string $category = null): string
    {
        $words = preg_split('/\s+/', trim($query));
        $words = array_filter($words, fn($w) => mb_strlen($w) >= 2);

        if (empty($words)) {
            return json_encode(['results' => [], 'message' => 'Requête de recherche trop courte'], JSON_UNESCAPED_UNICODE);
        }

        // Build relevance score: count how many words match (title matches weighted 2x)
        $scoreExpressions = [];
        $wordConditions = [];
        foreach ($words as $word) {
            $escaped = $this->db->escape($word);
            $wordConditions[] = "(k.title LIKE '%" . $escaped . "%' OR k.content LIKE '%" . $escaped . "%')";
            // Title match = 2 points, content match = 1 point
            $scoreExpressions[] = "(CASE WHEN k.title LIKE '%" . $escaped . "%' THEN 2 ELSE 0 END"
                . " + CASE WHEN k.content LIKE '%" . $escaped . "%' THEN 1 ELSE 0 END)";
        }

        $scoreSQL = implode(' + ', $scoreExpressions);

        $sql = "SELECT k.rowid, k.fk_user, k.title, k.content, k.category, k.scope, k.date_creation,"
            . " (" . $scoreSQL . ") as relevance"
            . " FROM " . MAIN_DB_PREFIX . "dalfred_knowledge as k"
            . " WHERE k.entity = " . (int) $this->entityId
            . " AND (k.fk_user = " . (int) $this->userId . " OR k.scope = 'shared')"
            . " AND (" . implode(' OR ', $wordConditions) . ")";

        if ($category) {
            $sql .= " AND k.category = '" . $this->db->escape($category) . "'";
        }

        $sql .= " ORDER BY relevance DESC, k.date_creation DESC";
        $sql .= " LIMIT 20";

        $results = [];
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $results[] = [
                    'id' => (int) $obj->rowid,
                    'title' => $obj->title,
                    'content' => $obj->content,
                    'category' => $obj->category,
                    'scope' => $obj->scope,
                    'date' => $obj->date_creation,
                ];
            }
        }

        if (empty($results)) {
            return json_encode([
                'results' => [],
                'message' => "Aucune information trouvée pour \"{$query}\". Essaie avec d'autres synonymes ou utilise knowledge_list pour parcourir toutes les entrées.",
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'results' => $results,
            'count' => count($results),
        ], JSON_UNESCAPED_UNICODE);
    }
}
