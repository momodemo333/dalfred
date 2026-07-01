<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SmartQueryDeleteTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'smart_query_delete',
            description: 'Supprimer une requête Smart Query sauvegardée. Seul le propriétaire peut supprimer.',
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
                description: 'ID de la Smart Query à supprimer',
                required: true,
            ),
        ];
    }

    public function __invoke(int $query_id): string
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries"
            . " WHERE rowid = " . (int) $query_id
            . " AND entity = " . (int) $this->entityId
            . " AND fk_user = " . (int) $this->userId;

        $result = $this->db->query($sql);
        if ($result && $this->db->affected_rows($result) > 0) {
            return json_encode([
                'success' => true,
                'message' => "Requête #{$query_id} supprimée.",
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => false,
            'error' => 'Requête non trouvée ou tu n\'es pas le propriétaire.',
        ], JSON_UNESCAPED_UNICODE);
    }
}
