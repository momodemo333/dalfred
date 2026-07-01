<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use Dalfred\Service\CommandResolver;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CommandUpdateTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'command_update',
            description: 'Modifier une commande slash existante (titre, contenu, ou scope). Utilise le nom actuel de la commande pour la cibler.',
        );
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(name: 'name', type: PropertyType::STRING,
                description: 'Nom actuel de la commande à modifier (sans slash).', required: true),
            ToolProperty::make(name: 'title', type: PropertyType::STRING,
                description: 'Nouveau titre. Optionnel — laisse vide pour conserver.', required: false),
            ToolProperty::make(name: 'content', type: PropertyType::STRING,
                description: 'Nouveau contenu (prompt). Optionnel.', required: false),
            ToolProperty::make(name: 'scope', type: PropertyType::STRING,
                description: 'Nouvelle portée (private/shared). Optionnel.', required: false),
        ];
    }

    public function __invoke(string $name, ?string $title = null, ?string $content = null, ?string $scope = null): string
    {
        if (!CommandResolver::isValidName($name)) {
            return json_encode(['success' => false, 'error' => 'Nom de commande invalide.'], JSON_UNESCAPED_UNICODE);
        }

        $resolver = new CommandResolver($this->db);
        $existing = $resolver->resolve($name, $this->userId, $this->entityId);
        if ($existing === null) {
            return json_encode(['success' => false, 'error' => "Commande /{$name} introuvable."], JSON_UNESCAPED_UNICODE);
        }

        $sets = [];
        if ($title !== null && $title !== '') {
            $title = str_replace(['\\r\\n', '\\r', '\\n'], ["\r\n", "\r", "\n"], $title);
            $sets[] = "title = '" . $this->db->escape($title) . "'";
        }
        if ($content !== null && $content !== '') {
            $content = str_replace(['\\r\\n', '\\r', '\\n'], ["\r\n", "\r", "\n"], $content);
            $sets[] = "content = '" . $this->db->escape($content) . "'";
        }
        if ($scope !== null && in_array($scope, ['private', 'shared'], true)) {
            $sets[] = "scope = '" . $this->db->escape($scope) . "'";
        }

        if (empty($sets)) {
            return json_encode(['success' => false, 'error' => 'Aucun champ à modifier (passe au moins title, content ou scope).'], JSON_UNESCAPED_UNICODE);
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " SET " . implode(', ', $sets)
            . " WHERE rowid = " . (int) $existing['rowid'];
        if (!$this->db->query($sql)) {
            return json_encode(['success' => false, 'error' => 'Erreur DB: ' . $this->db->lasterror()], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => true,
            'id' => $existing['rowid'],
            'name' => $name,
            'message' => "Commande /{$name} mise à jour.",
        ], JSON_UNESCAPED_UNICODE);
    }
}
