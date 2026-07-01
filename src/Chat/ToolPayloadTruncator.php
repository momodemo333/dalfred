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
        if ($this->maxPayloadChars === 0 || $isLatestPair) {
            return $message;
        }

        try {
            if ($message instanceof ToolCallMessage) {
                $this->truncateCall($message);
            } elseif ($message instanceof ToolResultMessage) {
                $this->truncateResult($message);
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

    private function truncateCall(ToolCallMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $inputs = $tool->getInputs();
            $modified = false;

            foreach ($inputs as $key => $value) {
                if (!\is_string($value)) {
                    continue;
                }
                if (\strlen($value) <= $this->maxPayloadChars) {
                    continue;
                }
                $inputs[$key] = $this->buildInputPlaceholder($tool, (string) $key, $value);
                $modified = true;
            }

            if ($modified) {
                $tool->setInputs($inputs);
                $this->logElision($tool, 'inputs');
            }
        }
    }

    private function truncateResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $result = $tool->getResult();
            if (\strlen($result) <= $this->maxPayloadChars) {
                continue;
            }
            $tool->setResult($this->buildResultPlaceholder($tool, $result));
            $this->logElision($tool, 'result');
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

    private function logElision(ToolInterface $tool, string $field): void
    {
        if (!\function_exists('dol_syslog')) {
            return;
        }
        \dol_syslog(
            \sprintf(
                '[Dalfred Truncator] tool=%s field=%s elision applied (threshold=%d chars)',
                $tool->getName(),
                $field,
                $this->maxPayloadChars
            ),
            LOG_INFO
        );
    }
}
