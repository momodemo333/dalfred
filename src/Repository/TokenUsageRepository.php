<?php

declare(strict_types=1);

namespace Dalfred\Repository;

/**
 * Repository for llx_dalfred_token_usage.
 *
 * Persists one row per LLM inference and provides aggregates for the chat
 * badge and the admin dashboard.
 *
 * Uses a PDO connection (the same PDO that backs SafeSQLChatHistory) so it
 * can be unit-tested with SQLite in-memory and reused identically against
 * MariaDB in production.
 *
 * The table name is MAIN_DB_PREFIX . 'dalfred_token_usage' in production;
 * for the in-memory test the prefix is empty.
 *
 * Note: not declared `final`. The companion test (TokenUsageObserverTest)
 * defines a FakeRepo that extends this class with a no-op constructor to
 * avoid touching PDO. No production subclasses are expected.
 */
class TokenUsageRepository
{
    private \PDO $pdo;
    private string $table;

    public function __construct(\PDO $pdo, ?string $table = null)
    {
        $this->pdo = $pdo;
        $this->table = $table ?? (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX . 'dalfred_token_usage' : 'dalfred_token_usage');
    }

    /**
     * Insert a single inference record.
     *
     * @param array{
     *   entity:int, fk_user:int, thread_id:string, model:string, provider:string,
     *   input_tokens:int, output_tokens:int, duration_ms:int,
     *   tool_calls_count:int, context_window:int
     * } $data
     */
    public function insert(array $data): void
    {
        $sql = "INSERT INTO {$this->table} "
            . "(entity, fk_user, thread_id, model, provider, input_tokens, output_tokens, duration_ms, tool_calls_count, context_window, date_creation) "
            . "VALUES (:entity, :fk_user, :thread_id, :model, :provider, :input_tokens, :output_tokens, :duration_ms, :tool_calls_count, :context_window, :date_creation)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':entity' => $data['entity'],
            ':fk_user' => $data['fk_user'],
            ':thread_id' => $data['thread_id'] !== '' ? $data['thread_id'] : 'unknown',
            ':model' => $data['model'],
            ':provider' => $data['provider'],
            ':input_tokens' => $data['input_tokens'],
            ':output_tokens' => $data['output_tokens'],
            ':duration_ms' => $data['duration_ms'],
            ':tool_calls_count' => $data['tool_calls_count'],
            ':context_window' => $data['context_window'],
            ':date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{
     *   input_tokens:int, output_tokens:int, inference_count:int,
     *   last_input_tokens:int, last_output_tokens:int,
     *   last_model:string, last_context_window:int
     * }
     *
     * `input_tokens` / `output_tokens` are cumulative sums across every
     * inference of the thread (useful for cost tracking).
     * `last_input_tokens` / `last_output_tokens` come from the most recent
     * inference only — they represent the actual context size sent to the
     * model on the last call, and are what should drive the chat badge
     * saturation gauge. Summed inputs double-count the conversation history
     * because each inference re-sends every prior turn, so they cannot be
     * compared to the context window directly.
     */
    public function getTotalForThread(string $threadId, int $entity): array
    {
        $sql = "SELECT
                  COALESCE(SUM(input_tokens), 0)  AS input_tokens,
                  COALESCE(SUM(output_tokens), 0) AS output_tokens,
                  COUNT(*) AS inference_count
                FROM {$this->table}
                WHERE thread_id = :thread_id AND entity = :entity";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':thread_id' => $threadId, ':entity' => $entity]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $sqlLast = "SELECT model, context_window, input_tokens, output_tokens
                    FROM {$this->table}
                    WHERE thread_id = :thread_id AND entity = :entity
                    ORDER BY rowid DESC LIMIT 1";
        $stmtLast = $this->pdo->prepare($sqlLast);
        $stmtLast->execute([':thread_id' => $threadId, ':entity' => $entity]);
        $last = $stmtLast->fetch(\PDO::FETCH_ASSOC) ?: [
            'model' => '', 'context_window' => 0,
            'input_tokens' => 0, 'output_tokens' => 0,
        ];

        return [
            'input_tokens'        => (int) ($row['input_tokens'] ?? 0),
            'output_tokens'       => (int) ($row['output_tokens'] ?? 0),
            'inference_count'     => (int) ($row['inference_count'] ?? 0),
            'last_input_tokens'   => (int) ($last['input_tokens'] ?? 0),
            'last_output_tokens'  => (int) ($last['output_tokens'] ?? 0),
            'last_model'          => (string) ($last['model'] ?? ''),
            'last_context_window' => (int) ($last['context_window'] ?? 0),
        ];
    }

    /**
     * Build the WHERE clause and params array shared by listForAdmin and countForAdmin.
     *
     * Uses array_key_exists + null/'' checks (not !empty()) so legitimate values
     * like user_id = 0 (system user) are honored as filters, not silently dropped.
     *
     * @param array{user_id?:int, model?:string, date_from?:string, date_to?:string} $filters
     * @return array{0: string[], 1: array<string,mixed>} [whereClauses, params]
     */
    private function buildAdminFilter(int $entity, array $filters): array
    {
        $where = ['entity = :entity'];
        $params = [':entity' => $entity];

        if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
            $where[] = 'fk_user = :user_id';
            $params[':user_id'] = (int) $filters['user_id'];
        }
        if (isset($filters['model']) && $filters['model'] !== '') {
            $where[] = 'model = :model';
            $params[':model'] = (string) $filters['model'];
        }
        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $where[] = 'date_creation >= :date_from';
            $params[':date_from'] = (string) $filters['date_from'];
        }
        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $where[] = 'date_creation <= :date_to';
            $params[':date_to'] = (string) $filters['date_to'];
        }

        return [$where, $params];
    }

    /**
     * List rows for the admin dashboard with filters and pagination.
     *
     * @param array{user_id?:int, model?:string, date_from?:string, date_to?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(int $entity, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildAdminFilter($entity, $filters);

        $sql = "SELECT rowid, entity, fk_user, thread_id, model, provider, input_tokens, output_tokens, duration_ms, tool_calls_count, context_window, date_creation "
             . "FROM {$this->table} "
             . "WHERE " . implode(' AND ', $where) . " "
             . "ORDER BY date_creation DESC, rowid DESC "
             . "LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count rows matching the filters (for pagination).
     *
     * @param array{user_id?:int, model?:string, date_from?:string, date_to?:string} $filters
     */
    public function countForAdmin(int $entity, array $filters = []): int
    {
        [$where, $params] = $this->buildAdminFilter($entity, $filters);

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * KPIs for the dashboard: total tokens, top user, heaviest thread (last N days).
     *
     * @return array{
     *   total_tokens:int, total_inferences:int,
     *   top_user_id:int, top_user_tokens:int,
     *   heaviest_thread_id:string, heaviest_thread_tokens:int
     * }
     */
    public function getKpis(int $entity, int $days = 7): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Total
        $sqlTotal = "SELECT COALESCE(SUM(input_tokens + output_tokens), 0) AS total_tokens, COUNT(*) AS n
                     FROM {$this->table}
                     WHERE entity = :entity AND date_creation >= :since";
        $stmt = $this->pdo->prepare($sqlTotal);
        $stmt->execute([':entity' => $entity, ':since' => $since]);
        $total = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['total_tokens' => 0, 'n' => 0];

        // Top user
        $sqlUser = "SELECT fk_user, COALESCE(SUM(input_tokens + output_tokens), 0) AS tokens
                    FROM {$this->table}
                    WHERE entity = :entity AND date_creation >= :since
                    GROUP BY fk_user ORDER BY tokens DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sqlUser);
        $stmt->execute([':entity' => $entity, ':since' => $since]);
        $topUser = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['fk_user' => 0, 'tokens' => 0];

        // Heaviest thread
        $sqlThread = "SELECT thread_id, COALESCE(SUM(input_tokens + output_tokens), 0) AS tokens
                      FROM {$this->table}
                      WHERE entity = :entity AND date_creation >= :since
                      GROUP BY thread_id ORDER BY tokens DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sqlThread);
        $stmt->execute([':entity' => $entity, ':since' => $since]);
        $topThread = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['thread_id' => '', 'tokens' => 0];

        return [
            'total_tokens'           => (int) $total['total_tokens'],
            'total_inferences'       => (int) $total['n'],
            'top_user_id'            => (int) $topUser['fk_user'],
            'top_user_tokens'        => (int) $topUser['tokens'],
            'heaviest_thread_id'     => (string) $topThread['thread_id'],
            'heaviest_thread_tokens' => (int) $topThread['tokens'],
        ];
    }

    /**
     * Daily tokens series for charting.
     *
     * @return array<int, array{date:string, tokens:int}>
     */
    public function getDailySeries(int $entity, int $days = 30): array
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $sql = "SELECT DATE(date_creation) AS d, COALESCE(SUM(input_tokens + output_tokens), 0) AS tokens
                FROM {$this->table}
                WHERE entity = :entity AND date_creation >= :since
                GROUP BY d ORDER BY d ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':entity' => $entity, ':since' => $since]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => [
            'date'   => (string) $r['d'],
            'tokens' => (int) $r['tokens'],
        ], $rows);
    }

    /**
     * Delete rows older than $days days for the given entity.
     *
     * @return int number of rows deleted
     */
    public function purgeOlderThan(int $entity, int $days): int
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE entity = :entity AND date_creation < :threshold"
        );
        $stmt->execute([':entity' => $entity, ':threshold' => $threshold]);
        return $stmt->rowCount();
    }
}
