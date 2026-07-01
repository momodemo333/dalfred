<?php

declare(strict_types=1);

namespace Dalfred\Observer;

use Dalfred\Repository\TokenUsageRepository;
use Dalfred\Service\ModelCapacityRegistry;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;

/**
 * Persists one row in llx_dalfred_token_usage per LLM inference.
 *
 * Listens to NeuronAI v3 events:
 *   - InferenceStart → record start time, reset tool counter
 *   - ToolCalled     → increment tool counter
 *   - InferenceStop  → read $response->getUsage() and insert
 *
 * Robustness:
 *   - try/catch around the whole onEvent body — never bubbles up
 *   - if the repository throws more than 3 times in this request lifetime
 *     (counter resets on each successful persist), the observer disables
 *     itself to avoid log spam
 *   - if Usage is missing on the response (some Ollama setups), inserts
 *     0/0 instead of failing
 */
final class TokenUsageObserver implements ObserverInterface
{
    private TokenUsageRepository $repo;
    private int $userId;
    private int $entityId;
    private ?string $threadId;
    private string $model;
    private string $provider;

    private ?float $startedAt = null;
    private int $toolCallsInFlight = 0;

    private int $errorCount = 0;
    private bool $disabled = false;
    private const MAX_ERRORS_BEFORE_DISABLE = 3;

    public function __construct(
        TokenUsageRepository $repo,
        int $userId,
        int $entityId,
        ?string $threadId,
        string $model,
        string $provider
    ) {
        $this->repo = $repo;
        $this->userId = $userId;
        $this->entityId = $entityId;
        $this->threadId = $threadId;
        $this->model = $model;
        $this->provider = $provider;
    }

    public function setThreadId(?string $threadId): void
    {
        $this->threadId = $threadId;
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if ($this->disabled) {
            return;
        }

        try {
            if ($data instanceof InferenceStart) {
                $this->startedAt = microtime(true);
                $this->toolCallsInFlight = 0;
                return;
            }

            if ($data instanceof ToolCalled) {
                $this->toolCallsInFlight++;
                return;
            }

            if ($data instanceof InferenceStop) {
                $this->persist($data);
                return;
            }
        } catch (\Throwable $e) {
            $this->errorCount++;
            if (function_exists('dol_syslog')) {
                dol_syslog(
                    '[Dalfred] TokenUsageObserver error (' . $this->errorCount . '): ' . $e->getMessage(),
                    defined('LOG_WARNING') ? LOG_WARNING : 4
                );
            }
            if ($this->errorCount >= self::MAX_ERRORS_BEFORE_DISABLE) {
                $this->disabled = true;
                if (function_exists('dol_syslog')) {
                    dol_syslog(
                        '[Dalfred] TokenUsageObserver self-disabled after ' . self::MAX_ERRORS_BEFORE_DISABLE . ' errors',
                        defined('LOG_ERR') ? LOG_ERR : 3
                    );
                }
            }
        }
    }

    private function persist(InferenceStop $event): void
    {
        $usage = $event->response->getUsage();
        $input = $usage !== null ? $usage->inputTokens : 0;
        $output = $usage !== null ? $usage->outputTokens : 0;

        $duration = 0;
        if ($this->startedAt !== null) {
            $duration = (int) ((microtime(true) - $this->startedAt) * 1000);
            $this->startedAt = null;
        }

        $window = ModelCapacityRegistry::get($this->model);

        $this->repo->insert([
            'entity'           => $this->entityId,
            'fk_user'          => $this->userId,
            'thread_id'        => $this->threadId ?? 'unknown',
            'model'            => $this->model,
            'provider'         => $this->provider,
            'input_tokens'     => $input,
            'output_tokens'    => $output,
            'duration_ms'      => $duration,
            'tool_calls_count' => $this->toolCallsInFlight,
            'context_window'   => $window,
        ]);

        // Reset error counter on success.
        $this->errorCount = 0;
        $this->toolCallsInFlight = 0;
    }
}
