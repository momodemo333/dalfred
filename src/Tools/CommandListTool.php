<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use Dalfred\Service\CommandResolver;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CommandListTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'command_list',
            description: 'Lister les commandes slash disponibles pour l\'utilisateur courant (privées + partagées de l\'entité). Utilise quand l\'utilisateur demande "quelles commandes j\'ai", "liste mes commandes", etc.',
        );
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(name: 'prefix', type: PropertyType::STRING,
                description: 'Préfixe optionnel pour filtrer les noms.', required: false),
        ];
    }

    public function __invoke(?string $prefix = null): string
    {
        $resolver = new CommandResolver($this->db);
        $commands = $resolver->listAvailable($this->userId, $this->entityId, $prefix, 100);

        return json_encode([
            'success' => true,
            'count' => count($commands),
            'commands' => $commands,
        ], JSON_UNESCAPED_UNICODE);
    }
}
