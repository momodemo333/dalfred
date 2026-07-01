<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use Dalfred\Service\CommandResolver;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CommandSaveTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'command_save',
            description: 'Sauvegarder une commande slash réutilisable. À utiliser quand l\'utilisateur dit "crée une commande", "retiens cette commande comme /xxx", "sauvegarde ça comme commande slash". Ne pas utiliser pour les knowledge entries classiques (utilise knowledge_save pour ça).',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Nom de la commande, sans le slash. Format strict : minuscules, chiffres, tirets uniquement (ex: "factures-mois", "recap2026").',
                required: true,
            ),
            ToolProperty::make(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Titre lisible de la commande (ex: "Factures du mois en cours").',
                required: true,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::STRING,
                description: 'Le prompt qui sera envoyé à l\'agent quand l\'utilisateur tape /<name>. C\'est le texte que la commande remplace.',
                required: true,
            ),
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'Portée : "private" (créateur uniquement) ou "shared" (tous les utilisateurs de l\'entité). Par défaut: "private".',
                required: false,
            ),
        ];
    }

    public function __invoke(string $name, string $title, string $content, ?string $scope = null): string
    {
        if (!CommandResolver::isValidName($name)) {
            return json_encode([
                'success' => false,
                'error' => 'Nom invalide. Utilise uniquement minuscules, chiffres et tirets (max 64 caractères). Exemples: factures-mois, recap-2026.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $scopeValue = in_array($scope, ['private', 'shared'], true) ? $scope : 'private';

        // Collision check — own private with same name OR shared in entity.
        $check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE entity = " . (int) $this->entityId
            . " AND command_name = '" . $this->db->escape($name) . "'"
            . " AND ((scope = 'private' AND fk_user = " . (int) $this->userId . ") OR scope = 'shared')"
            . " LIMIT 1";
        $resql = $this->db->query($check);
        if ($resql && $this->db->num_rows($resql) > 0) {
            return json_encode([
                'success' => false,
                'error' => "La commande /{$name} existe déjà. Demande à l'utilisateur s'il veut un autre nom (ex: /{$name}-2) ou utilise command_update pour modifier l'existante.",
            ], JSON_UNESCAPED_UNICODE);
        }

        // Normalize escaped newlines from LLM (same trick as KnowledgeSaveTool).
        $content = str_replace(['\\r\\n', '\\r', '\\n'], ["\r\n", "\r", "\n"], $content);
        $title = str_replace(['\\r\\n', '\\r', '\\n'], ["\r\n", "\r", "\n"], $title);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " (entity, fk_user, title, content, scope, command_name, date_creation)"
            . " VALUES ("
            . (int) $this->entityId . ", "
            . (int) $this->userId . ", "
            . "'" . $this->db->escape($title) . "', "
            . "'" . $this->db->escape($content) . "', "
            . "'" . $this->db->escape($scopeValue) . "', "
            . "'" . $this->db->escape($name) . "', "
            . "NOW())";

        if (!$this->db->query($sql)) {
            return json_encode([
                'success' => false,
                'error' => 'Erreur DB: ' . $this->db->lasterror(),
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => true,
            'id' => (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'dalfred_knowledge'),
            'name' => $name,
            'message' => "Commande /{$name} sauvegardée. L'utilisateur peut maintenant la taper dans le chat.",
        ], JSON_UNESCAPED_UNICODE);
    }
}
