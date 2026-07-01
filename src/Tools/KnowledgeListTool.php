<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class KnowledgeListTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'knowledge_list',
            description: 'Lister les entrées de la mémoire persistante avec pagination. Retourne uniquement les titres, catégories et métadonnées (pas le contenu complet). Utilise cet outil pour parcourir la mémoire, découvrir ce qui est disponible, ou quand tu ne sais pas quels mots-clés utiliser pour une recherche.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'page',
                type: PropertyType::INTEGER,
                description: 'Numéro de page (commence à 1). Par défaut : 1.',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Nombre d\'entrées par page (max 50). Par défaut : 20.',
                required: false,
            ),
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Filtrer par catégorie (optionnel)',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $page = null, ?int $limit = null, ?string $category = null): string
    {
        $page = max(1, $page ?? 1);
        $limit = min(50, max(1, $limit ?? 20));
        $offset = ($page - 1) * $limit;

        // Count total
        $sqlCount = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE entity = " . (int) $this->entityId
            . " AND (fk_user = " . (int) $this->userId . " OR scope = 'shared')";

        if ($category) {
            $sqlCount .= " AND category = '" . $this->db->escape($category) . "'";
        }

        $total = 0;
        $resql = $this->db->query($sqlCount);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $total = (int) $obj->total;
        }

        if ($total === 0) {
            return json_encode([
                'entries' => [],
                'total' => 0,
                'page' => $page,
                'pages' => 0,
                'message' => 'Aucune entrée en mémoire.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Fetch titles only (lightweight)
        $sql = "SELECT rowid, title, category, scope, date_creation"
            . " FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE entity = " . (int) $this->entityId
            . " AND (fk_user = " . (int) $this->userId . " OR scope = 'shared')";

        if ($category) {
            $sql .= " AND category = '" . $this->db->escape($category) . "'";
        }

        $sql .= " ORDER BY date_creation DESC";
        $sql .= " LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $entries = [];
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $entries[] = [
                    'id' => (int) $obj->rowid,
                    'title' => $obj->title,
                    'category' => $obj->category,
                    'scope' => $obj->scope,
                    'date' => $obj->date_creation,
                ];
            }
        }

        $totalPages = (int) ceil($total / $limit);

        return json_encode([
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'pages' => $totalPages,
            'limit' => $limit,
        ], JSON_UNESCAPED_UNICODE);
    }
}
