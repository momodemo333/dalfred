<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class KnowledgeUpdateTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'knowledge_update',
            description: 'Mettre à jour une entrée existante dans la mémoire persistante par son ID.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'id',
                type: PropertyType::INTEGER,
                description: 'ID de l\'entrée à modifier',
                required: true,
            ),
            ToolProperty::make(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Nouveau titre (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::STRING,
                description: 'Nouveau contenu (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Nouvelle catégorie (optionnel)',
                required: false,
            ),
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'Nouvelle portée : "private" ou "shared" (optionnel)',
                required: false,
            ),
        ];
    }

    public function __invoke(int $id, ?string $title = null, ?string $content = null, ?string $category = null, ?string $scope = null): string
    {
        // Normalize escaped newlines from LLM to real newlines
        if ($title !== null) {
            $title = str_replace(array("\\r\\n", "\\r", "\\n"), array("\r\n", "\r", "\n"), $title);
        }
        if ($content !== null) {
            $content = str_replace(array("\\r\\n", "\\r", "\\n"), array("\r\n", "\r", "\n"), $content);
        }

        // Build SET clause dynamically
        $sets = [];
        if ($title !== null) {
            $sets[] = "title = '" . $this->db->escape($title) . "'";
        }
        if ($content !== null) {
            $sets[] = "content = '" . $this->db->escape($content) . "'";
        }
        if ($category !== null) {
            $sets[] = "category = '" . $this->db->escape($category) . "'";
        }
        if ($scope !== null && in_array($scope, ['private', 'shared'])) {
            $sets[] = "scope = '" . $this->db->escape($scope) . "'";
        }

        if (empty($sets)) {
            return json_encode(['success' => false, 'error' => 'Aucun champ à modifier'], JSON_UNESCAPED_UNICODE);
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " SET " . implode(', ', $sets)
            . " WHERE rowid = " . (int) $id
            . " AND entity = " . (int) $this->entityId
            . " AND (fk_user = " . (int) $this->userId . " OR scope = 'shared')";

        $result = $this->db->query($sql);
        if ($result && $this->db->affected_rows($result) > 0) {
            return json_encode(['success' => true, 'message' => 'Information mise à jour'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['success' => false, 'error' => 'Entrée non trouvée ou non modifiable'], JSON_UNESCAPED_UNICODE);
    }
}
