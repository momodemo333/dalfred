<?php

declare(strict_types=1);

namespace Dalfred\Tools;

/**
 * In-memory history of tool calls within a single chat turn.
 *
 * Created once per call to DalfredAgent::tools() and shared between every
 * SafeToolWrapper of that turn. Used to detect consecutive identical tool
 * calls (the loop pattern observed with small Ollama models like Gemma 4)
 * and let the wrapper return a guidance payload instead of invoking the
 * underlying tool a 3rd time.
 *
 * The history is intentionally scoped to one chat turn: a user calling the
 * same tool across two separate user messages is not a loop, just two
 * similar questions. The instance is garbage-collected when the turn ends.
 */
class ToolCallHistory
{
    /** @var array<int, array{hash: string, toolName: string, inputs: array<string, mixed>, result: ?string}> */
    private array $entries = [];

    /**
     * Compute a deterministic hash for a tool call.
     *
     * Input keys are recursively sorted before serialization so two calls
     * with the same logical parameters in different key order produce the
     * same hash. Used as the consecutive-occurrence key.
     *
     * @param array<string, mixed> $inputs
     */
    public static function hashInputs(string $toolName, array $inputs): string
    {
        $normalized = self::recursiveKsort($inputs);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            // Defensive fallback: should not occur with JSON_INVALID_UTF8_SUBSTITUTE
            // set, but keeps the hash deterministic if future flag changes ever
            // re-enable failure paths.
            $json = '{}';
        }
        return hash('sha256', $toolName . '|' . $json);
    }

    /**
     * @param array<mixed, mixed> $array
     * @return array<mixed, mixed>
     */
    private static function recursiveKsort(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::recursiveKsort($value);
            }
        }
        return $array;
    }

    /**
     * Record that a tool call is about to be invoked. Stores the hash, the
     * tool name and the inputs. The result slot is initialized to null and
     * filled later by recordResult() once the call returns.
     *
     * @param array<string, mixed> $inputs
     */
    public function recordCall(string $hash, string $toolName, array $inputs): void
    {
        $this->entries[] = [
            'hash' => $hash,
            'toolName' => $toolName,
            'inputs' => $inputs,
            'result' => null,
        ];
    }

    /**
     * Attach the result string to the most recent entry matching the hash.
     * Idempotent: if no matching entry exists, this is a no-op.
     */
    public function recordResult(string $hash, string $result): void
    {
        for ($i = count($this->entries) - 1; $i >= 0; $i--) {
            if ($this->entries[$i]['hash'] === $hash) {
                $this->entries[$i]['result'] = $result;
                return;
            }
        }
    }

    /**
     * Count how many times this hash appears AT THE END of the sequence,
     * uninterrupted by any different hash. A different call in between
     * resets the count to zero for the original hash.
     *
     * Examples:
     *   sequence [A, A, A] → countConsecutiveOccurrences(A) == 3
     *   sequence [A, A, B] → countConsecutiveOccurrences(A) == 0, count(B) == 1
     *   sequence [A, B, A] → countConsecutiveOccurrences(A) == 1
     */
    public function countConsecutiveOccurrences(string $hash): int
    {
        $count = 0;
        for ($i = count($this->entries) - 1; $i >= 0; $i--) {
            if ($this->entries[$i]['hash'] !== $hash) {
                break;
            }
            $count++;
        }
        return $count;
    }

    /**
     * Return the most recent recorded result string for the given hash,
     * or null if no entry exists or the result is not yet set.
     */
    public function getLastResultFor(string $hash): ?string
    {
        for ($i = count($this->entries) - 1; $i >= 0; $i--) {
            if ($this->entries[$i]['hash'] === $hash && $this->entries[$i]['result'] !== null) {
                return $this->entries[$i]['result'];
            }
        }
        return null;
    }
}
