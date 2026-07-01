<?php

declare(strict_types=1);

namespace Dalfred\Agent\Nodes;

use Dalfred\Observer\ActivityLogObserver;
use Dalfred\Service\EmptyResponseGuard;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;

use function preg_match;
use function str_contains;

/**
 * Dalfred-specific override of NeuronAI's ChatNode. Two defensive behaviors
 * stacked on top of the stock inference call:
 *
 *  1. Convert ProviderException("non-existing tool: X") into a friendly
 *     fallback AssistantMessage (introduced in v2.10.1 to absorb model-side
 *     hallucinations of disabled toolkits).
 *
 *  2. Detect structurally empty provider responses, retry once, and emit a
 *     user-facing fallback + audit log when the retry also fails. The
 *     detection lives in EmptyResponseGuard and the audit is written via
 *     ActivityLogObserver::logEmptyProviderResponse(). See
 *     dev/2026-06-22-empty-response-guard-design.md for the full design.
 *
 * The constructor's extra parameters are all nullable so existing callers
 * that pass only $provider continue to work — the guard is auto-instantiated
 * with safe defaults.
 */
class DalfredChatNode extends ChatNode
{
    public function __construct(
        AIProviderInterface $provider,
        protected ?EmptyResponseGuard $guard = null,
        protected ?ActivityLogObserver $activityObserver = null,
        protected array $emptyResponseContext = []
    ) {
        parent::__construct($provider);
        $this->guard ??= new EmptyResponseGuard();
    }

    protected function inference(AIInferenceEvent $event, array $messages): Message
    {
        // First inference attempt.
        try {
            $first = parent::inference($event, $messages);
        } catch (ProviderException $e) {
            return $this->handleProviderException($e);
        }

        // If the response is healthy (real content OR a tool call OR a
        // legitimately empty STOP-like reason), pass it through.
        if (!$this->guard->shouldRetry($first)) {
            return $first;
        }

        // Pathological empty detected — log and retry once (Q2-A).
        if (\function_exists('dol_syslog')) {
            \dol_syslog(
                '[Dalfred EmptyGuard] empty response on first inference — retrying once'
                . ' (provider=' . ($this->emptyResponseContext['provider'] ?? 'unknown') . ')',
                LOG_INFO
            );
        }

        try {
            $second = parent::inference($event, $messages);
        } catch (ProviderException $e) {
            return $this->handleProviderException($e);
        }

        if (!$this->guard->shouldRetry($second)) {
            // Silent recovery — no error row, just the successful retry result.
            return $second;
        }

        // Double-empty confirmed. Record an audit row and return the fallback.
        $this->logDoubleEmpty(
            EmptyResponseGuard::normalizeStopReason($first),
            EmptyResponseGuard::normalizeStopReason($second)
        );
        return $this->guard->buildFallbackMessage();
    }

    /**
     * Convert ProviderException("non-existing tool: X") into a friendly
     * fallback AssistantMessage. Re-throws other ProviderExceptions unchanged.
     * Extracted from the previous inline try/catch for clarity.
     */
    private function handleProviderException(ProviderException $e): Message
    {
        if (!str_contains($e->getMessage(), 'non-existing tool')) {
            throw $e;
        }

        preg_match('/non-existing tool: (\w+)/', $e->getMessage(), $matches);
        $toolName = $matches[1] ?? 'unknown';

        error_log("[Dalfred] Model tried to call unavailable tool '{$toolName}' — returning friendly fallback");

        return new AssistantMessage(
            "Je n'ai pas accès à l'outil `{$toolName}`. "
            . "Il est possible que cette fonctionnalité ait été désactivée par l'administrateur. "
            . "Je vais essayer de répondre avec les outils dont je dispose actuellement."
        );
    }

    /**
     * Write the EmptyProviderResponse audit row when the activity observer is
     * wired. When it is not, degrade to a syslog ERR line so the incident is
     * not entirely silent.
     *
     * @param string $reason1 normalized stop reason of the first empty response (e.g. 'STOP')
     * @param string $reason2 normalized stop reason of the retry empty response
     */
    private function logDoubleEmpty(string $reason1, string $reason2): void
    {
        if ($this->activityObserver === null) {
            if (\function_exists('dol_syslog')) {
                \dol_syslog(
                    '[Dalfred EmptyGuard] DOUBLE EMPTY confirmed but no observer wired — fallback shown to user',
                    LOG_ERR
                );
            }
            return;
        }
        $this->activityObserver->logEmptyProviderResponse(
            $this->emptyResponseContext['provider'] ?? 'unknown',
            $this->emptyResponseContext['model'] ?? 'unknown',
            $this->emptyResponseContext['thread_id'] ?? null,
            [$reason1, $reason2]
        );
    }
}
