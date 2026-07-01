<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class KnowledgeDeleteTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'knowledge_delete',
            description: 'Supprimer une entrée de la mémoire persistante par son ID.',
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
                description: 'ID de l\'entrée à supprimer',
                required: true,
            ),
        ];
    }

    public function __invoke(int $id): string
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE rowid = " . (int) $id
            . " AND entity = " . (int) $this->entityId
            . " AND (fk_user = " . (int) $this->userId . " OR scope = 'shared')";

        $result = $this->db->query($sql);
        if ($result && $this->db->affected_rows($result) > 0) {
            return json_encode(['success' => true, 'message' => 'Information supprimée'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['success' => false, 'error' => 'Entrée non trouvée ou non supprimable'], JSON_UNESCAPED_UNICODE);
    }
}
