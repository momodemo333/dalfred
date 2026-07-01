<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class KnowledgeSaveTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'knowledge_save',
            description: 'Sauvegarder une information dans la mémoire persistante. Utilise cet outil quand l\'utilisateur te dit "retiens ça", "souviens-toi", "note que...".',
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
                description: 'Titre court et descriptif de l\'information à retenir',
                required: true,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::STRING,
                description: 'Contenu détaillé de l\'information',
                required: true,
            ),
            ToolProperty::make(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Catégorie optionnelle (ex: comptabilité, contacts, procédures, préférences)',
                required: false,
            ),
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'Portée : "private" (visible uniquement par cet utilisateur) ou "shared" (visible par tous les utilisateurs). Par défaut: "private".',
                required: false,
            ),
        ];
    }

    public function __invoke(string $title, string $content, ?string $category = null, ?string $scope = null): string
    {
        $scopeValue = in_array($scope, ['private', 'shared']) ? $scope : 'private';

        // Normalize escaped newlines from LLM to real newlines
        $content = str_replace(array("\\r\\n", "\\r", "\\n"), array("\r\n", "\r", "\n"), $content);
        $title = str_replace(array("\\r\\n", "\\r", "\\n"), array("\r\n", "\r", "\n"), $title);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " (entity, fk_user, title, content, category, scope, date_creation)"
            . " VALUES ("
            . (int) $this->entityId . ", "
            . (int) $this->userId . ", "
            . "'" . $this->db->escape($title) . "', "
            . "'" . $this->db->escape($content) . "', "
            . ($category ? "'" . $this->db->escape($category) . "'" : "NULL") . ", "
            . "'" . $this->db->escape($scopeValue) . "', "
            . "NOW()"
            . ")";

        $result = $this->db->query($sql);
        if ($result) {
            $id = $this->db->last_insert_id(MAIN_DB_PREFIX . 'dalfred_knowledge');
            return json_encode([
                'success' => true,
                'id' => $id,
                'scope' => $scopeValue,
                'message' => "Information sauvegardée : \"{$title}\" (portée: {$scopeValue})",
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => false,
            'error' => 'Erreur lors de la sauvegarde',
        ], JSON_UNESCAPED_UNICODE);
    }
}
