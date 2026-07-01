<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use Dalfred\Service\CommandResolver;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CommandDeleteTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'command_delete',
            description: 'Supprimer une commande slash. Ne supprime QUE la commande slash, pas les knowledge entries classiques.',
        );
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(name: 'name', type: PropertyType::STRING,
                description: 'Nom de la commande à supprimer (sans slash).', required: true),
        ];
    }

    public function __invoke(string $name): string
    {
        if (!CommandResolver::isValidName($name)) {
            return json_encode(['success' => false, 'error' => 'Nom invalide.'], JSON_UNESCAPED_UNICODE);
        }

        $resolver = new CommandResolver($this->db);
        $existing = $resolver->resolve($name, $this->userId, $this->entityId);
        if ($existing === null) {
            return json_encode(['success' => false, 'error' => "Commande /{$name} introuvable."], JSON_UNESCAPED_UNICODE);
        }

        // Defense in depth: only delete rows that are commands (command_name not null).
        // This prevents an accidental drop of a regular knowledge entry if rowid
        // collides somehow.
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE rowid = " . (int) $existing['rowid']
            . " AND command_name IS NOT NULL";
        if (!$this->db->query($sql)) {
            return json_encode(['success' => false, 'error' => 'Erreur DB: ' . $this->db->lasterror()], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => true,
            'message' => "Commande /{$name} supprimée.",
        ], JSON_UNESCAPED_UNICODE);
    }
}
