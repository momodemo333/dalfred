<?php

declare(strict_types=1);

namespace Dalfred\Chat;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

/**
 * Force the tool arrays carried by tool messages back into a PHP *list*
 * (keys 0..n-1, no holes).
 *
 * Why this exists
 * ---------------
 * `ToolCallMessage` and `ToolResultMessage` hold their tools in a plain array.
 * Nothing in the type system says that array must be a list, and several code
 * paths in NeuronAI hand back an array whose first key is not 0:
 *
 *  - `Providers/Gemini/HandleChat.php` selects the function calls out of the
 *    response with `array_filter($content['parts'], ...)`, which PRESERVES the
 *    original keys. When Gemini emits a `text` or `thought` part before the
 *    `functionCall` — routine with the 2.5 models — the result is `[1 => ...]`.
 *  - `AbstractChatHistory::deserializeToolCallResult()` rebuilds the tools with
 *    `array_map()` over the stored JSON, which also preserves keys. So once a
 *    hole reaches the database it is faithfully restored on every load.
 *
 * A holed array is invisible until it is serialised. `json_encode([1 => $x])`
 * emits an OBJECT `{"1": ...}`, not an array. `Providers/Gemini/MessageMapper::mapToolsResult()`
 * builds the `parts` payload with a key-preserving `array_map()`, so Google
 * receives `"parts": {"1": {...}}` where its schema demands a list and answers:
 *
 *     HTTP 400 — Invalid JSON payload received.
 *     Unknown name "1" at 'contents[32].parts': Cannot find field.
 *
 * The consequences are what the customer actually experiences. The bad message
 * is persisted, so every later turn re-sends it and gets the same 400: the
 * thread is dead for good, answering an error to every question — the same
 * end-user symptom as the broken alternation repaired in SafeSQLChatHistory,
 * reached by a completely different route. Observed at a customer on a thread
 * opened four months earlier, still failing after several module upgrades
 * because the corruption lived in their data, not in the code they updated.
 *
 * Implementation note: the `$tools` property is `protected` with no setter, and
 * rebuilding the message object would drop its metadata, usage and stop reason.
 * We therefore reindex in place through reflection, and only when the array is
 * genuinely holed — the normal path costs one `array_is_list()` call and never
 * touches reflection at all.
 */
final class ToolListNormalizer
{
    /**
     * Reindex the message's tools if needed.
     *
     * @return bool true when the message was actually repaired
     */
    public static function normalize(Message $message): bool
    {
        if (!$message instanceof ToolCallMessage && !$message instanceof ToolResultMessage) {
            return false;
        }

        $tools = $message->getTools();
        if (\array_is_list($tools)) {
            return false;
        }

        try {
            $property = new \ReflectionProperty($message, 'tools');
            $property->setAccessible(true);
            $property->setValue($message, \array_values($tools));
        } catch (\Throwable $e) {
            // Never let a repair attempt break the chat: an unrepaired message
            // fails one provider call, a thrown exception fails the whole turn.
            if (\function_exists('dol_syslog')) {
                \dol_syslog(
                    '[Dalfred ToolListNormalizer] could not reindex tools: '
                        . $e::class . ': ' . $e->getMessage(),
                    LOG_WARNING
                );
            }

            return false;
        }

        if (\function_exists('dol_syslog')) {
            \dol_syslog(
                '[Dalfred ToolListNormalizer] reindexed ' . \count($tools)
                    . ' tool(s) on a ' . $message::class . ' (keys were not a list)',
                LOG_NOTICE
            );
        }

        return true;
    }

    /**
     * Normalize a whole history.
     *
     * @param Message[] $messages
     * @return bool true when at least one message was repaired
     */
    public static function normalizeAll(array $messages): bool
    {
        $changed = false;

        foreach ($messages as $message) {
            if (self::normalize($message)) {
                $changed = true;
            }
        }

        return $changed;
    }
}
