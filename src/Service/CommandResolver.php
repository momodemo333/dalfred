<?php

declare(strict_types=1);

namespace Dalfred\Service;

/**
 * CommandResolver — parses /commands and resolves them against the knowledge table.
 *
 * Why parsing lives here instead of inline in chat.php: keeping the regex in
 * one place means the AJAX autocomplete endpoint, the chat handler, and any
 * future caller (CLI, API) all agree on what "starts with a slash" means.
 *
 * The slash itself is part of the wire format, not the storage format —
 * llx_dalfred_knowledge.command_name stores 'foo', not '/foo'.
 */
class CommandResolver
{
    /** Allowed name pattern. Tight on purpose — matches Slack/Discord style. */
    public const NAME_REGEX = '/^[a-z0-9-]{1,64}$/';

    /** @var \DoliDB|object Used by resolve()/listAvailable(). parse() does not touch it. */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Parse a user-typed message and extract the command name + remaining args.
     *
     * Returns null when the message does not start with a valid slash command.
     * The name pattern is intentionally strict (lowercase, digits, hyphens).
     *
     * @return array{name: string, args: string}|null
     */
    public function parse(string $message): ?array
    {
        // Must start with a literal slash at index 0 — no leading whitespace.
        if ($message === '' || $message[0] !== '/') {
            return null;
        }

        // Match /<name> where name is [a-z0-9-]+
        if (!preg_match('/^\/([a-z0-9-]+)(.*)$/s', $message, $m)) {
            return null;
        }

        $name = $m[1];
        // ltrim to drop the space(s) between name and args; preserve the rest.
        $args = ltrim($m[2]);

        return ['name' => $name, 'args' => $args];
    }

    /**
     * Resolve a command name to its stored entry. Visibility rule:
     * - the user's own private commands win over a shared command of the same name
     * - shared commands of the entity are visible to all users
     *
     * @return array{rowid: int, title: string, content: string, command_name: string, scope: string}|null
     */
    public function resolve(string $name, int $userId, int $entity): ?array
    {
        if (!preg_match(self::NAME_REGEX, $name)) {
            return null;
        }

        // ORDER BY: own private first (scope='private' AND fk_user matches),
        // then shared. LIMIT 1 picks the winner.
        $sql = "SELECT rowid, title, content, command_name, scope FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE entity = " . (int) $entity
            . " AND command_name = '" . $this->db->escape($name) . "'"
            . " AND ("
            . "   (scope = 'private' AND fk_user = " . (int) $userId . ")"
            . "   OR scope = 'shared'"
            . " )"
            . " ORDER BY (scope = 'private' AND fk_user = " . (int) $userId . ") DESC, rowid DESC"
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) === 0) {
            return null;
        }

        $row = $this->db->fetch_object($resql);
        return [
            'rowid' => (int) $row->rowid,
            'title' => (string) $row->title,
            'content' => (string) $row->content,
            'command_name' => (string) $row->command_name,
            'scope' => (string) $row->scope,
        ];
    }

    /**
     * List commands visible to the user (own private + entity shared).
     * Used by the autocomplete endpoint and by /help.
     *
     * @param string|null $prefix Optional prefix filter (matches start of name)
     * @param int         $limit  Max results (autocomplete: 20, /help: 100)
     * @return array<int, array{name: string, title: string, scope: string}>
     */
    public function listAvailable(int $userId, int $entity, ?string $prefix = null, int $limit = 20): array
    {
        $sql = "SELECT command_name, title, scope FROM " . MAIN_DB_PREFIX . "dalfred_knowledge"
            . " WHERE entity = " . (int) $entity
            . " AND command_name IS NOT NULL"
            . " AND ("
            . "   (scope = 'private' AND fk_user = " . (int) $userId . ")"
            . "   OR scope = 'shared'"
            . " )";

        if ($prefix !== null && $prefix !== '') {
            // Prefix is user-controlled — escape and cap length to avoid abuse.
            $safePrefix = $this->db->escape(substr($prefix, 0, 64));
            $sql .= " AND command_name LIKE '" . $safePrefix . "%'";
        }

        // Own private commands first (sort key is 1 vs 0), then alphabetical.
        $sql .= " ORDER BY (scope = 'private' AND fk_user = " . (int) $userId . ") DESC, command_name ASC";
        $sql .= " LIMIT " . (int) $limit;

        $resql = $this->db->query($sql);
        if (!$resql) {
            return [];
        }

        $result = [];
        while ($row = $this->db->fetch_object($resql)) {
            $result[] = [
                'name' => (string) $row->command_name,
                'title' => (string) $row->title,
                'scope' => (string) $row->scope,
            ];
        }
        return $result;
    }

    /**
     * Validate a name against the allowed pattern. Public so admin pages and
     * tools can pre-validate user input before hitting the DB.
     */
    public static function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_REGEX, $name);
    }
}
