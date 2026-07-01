<?php

declare(strict_types=1);

namespace Dalfred\Service;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;

/**
 * Decides whether a provider-returned Message is structurally empty in the
 * pathological sense and should trigger a retry.
 *
 * The rule (Q1-B from the design doc):
 *   - ToolCallMessage is never considered empty (the tool call IS the content).
 *   - stopReason different from STOP (case-insensitive) means the empty
 *     content is legitimately empty (MAX_TOKENS, SAFETY, length, etc.) and a
 *     retry would repeat the same condition. Do not retry.
 *   - stopReason STOP (or null, which we treat as STOP for safety) plus an
 *     empty/null/whitespace-only content is the Gemini-class bug: provider
 *     claims success but emitted nothing. Retry.
 *
 * The class has no state. Each method is a pure function of its arguments.
 *
 * Design: dev/2026-06-22-empty-response-guard-design.md
 */
final class EmptyResponseGuard
{
    /**
     * The Q3-A user-facing fallback message. Single source of truth; do not
     * duplicate this string anywhere else in the codebase. Provider name is
     * deliberately omitted ("fournisseur d'IA") so the copy works identically
     * for Gemini, Ollama, Mistral, etc.
     */
    private const FALLBACK_TEXT = "Je n'ai pas réussi à formuler une réponse cette fois — le fournisseur d'IA a renvoyé un résultat vide. Reformule ta demande ou réessaye dans quelques instants. Si le problème persiste, signale-le à ton administrateur (un événement a été enregistré dans le journal d'activité).";

    public function shouldRetry(Message $message): bool
    {
        if ($message instanceof ToolCallMessage) {
            return false;
        }

        $stopReason = $message->stopReason();
        if ($stopReason !== null && strtoupper((string) $stopReason) !== 'STOP') {
            return false;
        }

        $content = $message->getContent();
        if ($content === null) {
            return true;
        }
        return trim($content) === '';
    }

    public function buildFallbackMessage(): AssistantMessage
    {
        return new AssistantMessage(self::FALLBACK_TEXT);
    }

    public static function normalizeStopReason(Message $message): string
    {
        $stopReason = $message->stopReason();
        if ($stopReason === null) {
            return 'UNKNOWN';
        }
        return strtoupper((string) $stopReason);
    }
}
