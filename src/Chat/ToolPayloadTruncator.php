<?php

declare(strict_types=1);

namespace Dalfred\Chat;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;

/**
 * Rewrites the inputs and results of large tool calls in chat history so the
 * persisted JSON stays small and the LLM does not re-receive the same blob at
 * every subsequent turn.
 *
 * Triggered from {@see SafeSQLChatHistory::setMessages()} on every persistence
 * pass. The latest tool-call/result pair in the history is left untouched
 * (one turn of grace, useful for the immediate follow-up). Any earlier pair
 * whose payload exceeds the threshold is rewritten in place to a structured
 * placeholder that keeps the tool name, the non-bulky args, and a short
 * marker indicating an elision happened.
 *
 * Design ref: dev/2026-06-05-tool-payload-truncation-design.md
 */
final class ToolPayloadTruncator
{
    /**
     * Hard floor for the payload threshold. Any positive value below this is
     * clamped up to avoid edge cases where our own placeholders would exceed
     * the limit and recurse.
     */
    private const MIN_PAYLOAD_CHARS = 500;

    /**
     * Absolute ceiling, applied even to the latest tool pair and even when the
     * feature is otherwise disabled.
     *
     * The grace given to the latest pair used to be unconditional. That is how a
     * customer thread died in production: analyze_mysql_database_schema returned
     * a very large result, and since setMessages() rewrites the WHOLE history in
     * a single UPDATE, MySQL refused it with "Got a packet bigger than
     * 'max_allowed_packet' bytes". The assistant message was therefore never
     * stored, and the hole it left in the history bricked the conversation on
     * the next turn.
     *
     * 256 KB sits far above any legitimate tool payload and far below any
     * realistic max_allowed_packet (1 MB on a default XAMPP, 4 MB or more
     * elsewhere), so the write goes through. A truncated result is worth more
     * than one that cannot be persisted at all.
     */
    private const ABSOLUTE_MAX_PAYLOAD_CHARS = 262144;

    private int $maxPayloadChars;

    public function __construct(int $maxPayloadChars = 8000)
    {
        if ($maxPayloadChars <= 0) {
            // 0 (or negative) means "feature disabled": bypass everything.
            $this->maxPayloadChars = 0;
        } else {
            $this->maxPayloadChars = max(self::MIN_PAYLOAD_CHARS, $maxPayloadChars);
        }
    }

    public static function approxTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Mutates the passed message in place if applicable and returns it.
     * Non-tool messages and the latest tool pair pass through untouched.
     */
    public function truncateForPersistence(Message $message, bool $isLatestPair): Message
    {
        // The latest pair keeps its grace, but only up to the absolute ceiling:
        // beyond it the row would not be written at all. The ceiling also holds
        // when truncation is otherwise switched off, since it exists to keep the
        // UPDATE within what the server accepts, not to save context.
        $threshold = $isLatestPair || $this->maxPayloadChars === 0
            ? self::ABSOLUTE_MAX_PAYLOAD_CHARS
            : $this->maxPayloadChars;

        try {
            if ($message instanceof ToolCallMessage) {
                $this->truncateCall($message, $threshold);
            } elseif ($message instanceof ToolResultMessage) {
                $this->truncateResult($message, $threshold);
            }
        } catch (\Throwable $e) {
            // Truncation must never block the chat. Log and return the message untouched.
            if (\function_exists('dol_syslog')) {
                \dol_syslog(
                    '[Dalfred Truncator] swallowed exception: ' . $e::class . ': ' . $e->getMessage(),
                    LOG_WARNING
                );
            }
        }

        return $message;
    }

    private function truncateCall(ToolCallMessage $message, int $threshold): void
    {
        foreach ($message->getTools() as $tool) {
            $inputs = $tool->getInputs();
            $modified = false;

            foreach ($inputs as $key => $value) {
                if (!\is_string($value)) {
                    continue;
                }
                if (\strlen($value) <= $threshold) {
                    continue;
                }
                $inputs[$key] = $this->buildInputPlaceholder($tool, (string) $key, $value);
                $modified = true;
            }

            if ($modified) {
                $tool->setInputs($inputs);
                $this->logElision($tool, 'inputs', $threshold);
            }
        }
    }

    private function truncateResult(ToolResultMessage $message, int $threshold): void
    {
        foreach ($message->getTools() as $tool) {
            $result = $tool->getResult();
            if (\strlen($result) <= $threshold) {
                continue;
            }
            $tool->setResult($this->buildResultPlaceholder($tool, $result));
            $this->logElision($tool, 'result', $threshold);
        }
    }

    private function buildInputPlaceholder(ToolInterface $tool, string $key, string $original): string
    {
        $size = \strlen($original);

        if ($tool->getName() === 'dolibarr_files_create' && $key === 'content') {
            return \sprintf(
                '[elided — %d bytes written to disk, see download_url in result]',
                $size
            );
        }

        return \sprintf(
            '[elided — %d chars, original tool arg truncated to keep history light]',
            $size
        );
    }

    private function buildResultPlaceholder(ToolInterface $tool, string $original): string
    {
        $size = \strlen($original);
        // Re-serialize inputs AFTER any case-2 truncation so we don't reinject
        // the blob through this placeholder.
        $inputsJson = \json_encode(
            $tool->getInputs(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($inputsJson === false) {
            $inputsJson = '{}';
        }
        return \sprintf(
            '[elided — %d chars of result. Tool: %s, inputs: %s]',
            $size,
            $tool->getName(),
            $inputsJson
        );
    }

    private function logElision(ToolInterface $tool, string $field, int $threshold): void
    {
        if (!\function_exists('dol_syslog')) {
            return;
        }
        \dol_syslog(
            \sprintf(
                '[Dalfred Truncator] tool=%s field=%s elision applied (threshold=%d chars)',
                $tool->getName(),
                $field,
                $threshold
            ),
            LOG_INFO
        );
    }
}
