<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

/**
 * Decorator over a NeuronAI Tool that adds two protections without changing
 * the public contract:
 *
 * 1. Detection of consecutive identical tool calls. After
 *    self::MAX_IDENTICAL_CONSECUTIVE_CALLS occurrences with the same
 *    (toolName, inputs) hash, the wrapper intercepts and returns a guidance
 *    payload to the LLM instead of invoking the inner tool again.
 *
 * 2. Robustness against null/Throwable. The inner callable is wrapped in a
 *    try/catch (\Throwable) so any PHP exception is converted to a JSON
 *    error message the LLM can read. A null return is also converted to a
 *    structured error. This guarantees Tool::getResult(): string never
 *    raises a TypeError because $this->result was left at its default null
 *    value (the historical 2.15.1 / Gemma 4 crash mode).
 *
 * See dev/2026-05-26-safe-tool-wrapper-design.md for the full rationale.
 */
class SafeToolWrapper extends Tool
{
    /** After this many consecutive identical calls, intercept and return guidance. */
    public const MAX_IDENTICAL_CONSECUTIVE_CALLS = 3;

    /** Truncate the recalled previous result to this many chars in the guidance payload. */
    private const PREVIOUS_RESULT_PREVIEW_LIMIT = 500;

    private ToolInterface $inner;
    private ToolCallHistory $history;

    private function __construct(ToolInterface $inner, ToolCallHistory $history)
    {
        $this->inner = $inner;
        $this->history = $history;

        parent::__construct(
            name: $inner->getName(),
            description: $inner->getDescription()
        );

        foreach ($inner->getProperties() as $property) {
            $this->addProperty($property);
        }

        // Substitute this wrapper's callable so that NeuronAI's Tool::execute()
        // path (which does $this->callback(...$parameters)) runs our guarded
        // version. The inner tool's original callable is captured here and
        // invoked from inside the guard.
        //
        // IMPORTANT: NeuronAI v3 may clone the wrapper between turns (the
        // findTool() path does setInputs() on a different instance than the
        // one this closure is bound to). We therefore MUST derive the inputs
        // from $args (which DO carry the actual call's named parameters)
        // rather than from $this->getInputs() (which can be stale/empty on
        // the closure's original `$this`). The $args here are PHP named
        // arguments captured as an associative array — name => value.
        $originalCallable = $this->extractInnerCallable($inner);
        $this->setCallable(function (...$args) use ($originalCallable) {
            return $this->guardedInvoke($originalCallable, $args);
        });
    }

    /**
     * Wrap a tool with safety guards. Idempotent: wrap(wrap(t, h), h) === wrap(t, h).
     *
     * PRECONDITION: All wrappers within a single chat turn must share the same
     * ToolCallHistory instance. If wrap() is ever called twice on the same tool
     * with two different history instances, the second call returns the first
     * wrapper unchanged — the second history is silently discarded. This is
     * acceptable under the design (one history per turn, see DalfredAgent::tools())
     * but callers must not violate the invariant.
     *
     * The returned value is a ToolInterface and can be passed to
     * NeuronAI's withTools() exactly like the original.
     */
    public static function wrap(ToolInterface $tool, ToolCallHistory $history): ToolInterface
    {
        if ($tool instanceof self) {
            return $tool;
        }
        return new self($tool, $history);
    }

    /**
     * Expose the underlying tool. Intended for diagnostics and testing only —
     * invoking the inner tool directly (e.g. getInnerTool()->execute()) bypasses
     * all the safety guards this wrapper provides. Production code should
     * always go through the wrapper.
     */
    public function getInnerTool(): ToolInterface
    {
        return $this->inner;
    }

    /**
     * Reflect into the inner Tool to read its private $callback property.
     * NeuronAI v3 stores the callable there but does not expose a getter,
     * so reflection is the cleanest way to capture it without modifying
     * upstream code. If the inner tool overrides invocation via __invoke
     * (no callback set), we wrap a closure that calls __invoke instead.
     */
    private function extractInnerCallable(ToolInterface $inner): callable
    {
        // Tools that implement __invoke without setCallable are valid in
        // NeuronAI (cf. Tool::execute() lines 289-295). Detect this case.
        if (!($inner instanceof Tool)) {
            if (is_callable($inner)) {
                return fn (...$args) => $inner(...$args);
            }
            return fn (...$args) => '';
        }

        try {
            $reflection = new \ReflectionClass($inner);
            if (!$reflection->hasProperty('callback')) {
                if (is_callable($inner)) {
                    return fn (...$args) => $inner(...$args);
                }
                return fn (...$args) => '';
            }
            $prop = $reflection->getProperty('callback');
            $prop->setAccessible(true);
            $cb = $prop->getValue($inner);
            if (is_callable($cb)) {
                return $cb;
            }
        } catch (\Throwable $e) {
            // Fall through to __invoke fallback below.
        }

        if (is_callable($inner)) {
            return fn (...$args) => $inner(...$args);
        }
        return fn (...$args) => '';
    }

    /**
     * Encode a payload as JSON, falling back to a hardcoded string if encoding
     * fails for any reason. Used at every site where we build an error/guidance
     * payload — keeps the fallback consistent and the call sites uncluttered.
     *
     * @param array<string, mixed> $payload
     */
    private static function safeJsonEncode(array $payload, string $fallback): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $encoded === false ? $fallback : $encoded;
    }

    /**
     * Set inputs on the wrapper AND mirror them on the inner tool so that
     * if anything reads inputs from the inner (debug, logging), they see
     * the same payload.
     *
     * Note: the loop detector lives in guardedInvoke(), NOT here. NeuronAI
     * v3's findTool() path can hand setInputs() a different instance than
     * the one Tool::execute()'s callback was bound to, so any state we set
     * here on $this would not be visible to the closure. The guard runs at
     * actual invocation time instead, where the named args are guaranteed
     * to reflect the live call.
     *
     * @param array<string, mixed>|null $inputs
     */
    public function setInputs(?array $inputs): self
    {
        parent::setInputs($inputs);
        // Mirror onto the inner so getInnerTool()->getInputs() stays consistent.
        $this->inner->setInputs($inputs ?? []);
        return $this;
    }

    /**
     * Invoke the inner callable defensively. Catches every Throwable (not just
     * Exception — TypeError extends Error) and coerces null/non-string returns
     * into a JSON error payload. Records the call and the result in history.
     *
     * @param array<int, mixed> $args
     */
    private function guardedInvoke(callable $fn, array $args): string
    {
        $name = $this->getName();

        // CRITICAL: derive the inputs from $args (the named parameters PHP
        // captured into the variadic), NOT from $this->getInputs(). NeuronAI
        // v3's findTool() path can hand setInputs() a different instance than
        // the one this closure was bound to in the constructor, leaving
        // $this->getInputs() permanently empty for the closure's $this. The
        // $args parameter, by contrast, always reflects the live call —
        // PHP 8 variadic preserves the keys for named arguments.
        // Pre-2.24.x bug: when $this->getInputs() was used here, every call
        // shared the same hash (hash of {}) and the 4th identical-looking
        // call short-circuited via getResult() which returned null because
        // setResult had never run, raising TypeError.
        $inputs = $args;
        $hash = ToolCallHistory::hashInputs($name, $inputs);

        $existing = $this->history->countConsecutiveOccurrences($hash);
        if ($existing >= self::MAX_IDENTICAL_CONSECUTIVE_CALLS) {
            // The LLM has called this tool 3+ times in a row with identical
            // inputs. Returning the previous result again is unhelpful — the
            // model would just keep looping. Build a structured guidance
            // payload that tells it to stop, including a preview of the
            // previous result so it can use what it already has.
            $previous = $this->history->getLastResultFor($hash) ?? '';
            if (strlen($previous) > self::PREVIOUS_RESULT_PREVIEW_LIMIT) {
                $previous = substr($previous, 0, self::PREVIOUS_RESULT_PREVIEW_LIMIT) . '...';
            }
            $previousWasError = str_starts_with($previous, '{"error":true');
            $previousFraming = $previousWasError
                ? 'Previous identical calls also returned errors (last error: ' . $previous . ')'
                : 'The result of the previous call was: ' . $previous;
            return self::safeJsonEncode(
                [
                    'error' => true,
                    'code' => 'RepeatedToolCallDetected',
                    'message' => 'You have already called this tool ' . self::MAX_IDENTICAL_CONSECUTIVE_CALLS
                        . ' times with identical parameters in this turn. Calling it again will not change the outcome. '
                        . $previousFraming
                        . '. Stop calling this tool. Either: (a) use the data you already have to answer the user, (b) call a different tool, or (c) ask the user for clarification.',
                ],
                '{"error":true,"code":"RepeatedToolCallDetected","message":"Loop detected."}'
            );
        }

        $this->history->recordCall($hash, $name, $inputs);

        try {
            $result = $fn(...$args);
        } catch (\Throwable $e) {
            if (function_exists('dol_syslog')) {
                dol_syslog('[DALFRED] SafeToolWrapper caught throwable from ' . $name
                    . ': ' . $e->getMessage(), LOG_ERR);
            }
            $encoded = self::safeJsonEncode(
                [
                    'error' => true,
                    'code' => 'ToolExecutionFailed',
                    'message' => 'The tool encountered an error: ' . $e->getMessage()
                        . '. Try a different approach or different parameters, or ask the user for clarification.',
                ],
                '{"error":true,"code":"ToolExecutionFailed","message":"Tool failed."}'
            );
            $this->history->recordResult($hash, $encoded);
            return $encoded;
        }

        if ($result === null) {
            if (function_exists('dol_syslog')) {
                dol_syslog('[DALFRED] SafeToolWrapper got null from ' . $name, LOG_WARNING);
            }
            $encoded = self::safeJsonEncode(
                [
                    'error' => true,
                    'code' => 'ToolReturnedNull',
                    'message' => 'The tool completed but returned no data. This usually means an internal error.'
                        . ' Try a different approach.',
                ],
                '{"error":true,"code":"ToolReturnedNull","message":"Tool returned nothing."}'
            );
            $this->history->recordResult($hash, $encoded);
            return $encoded;
        }

        if (!is_string($result)) {
            // Arrays: serialize directly as JSON, matching the parent
            // Tool::setResult() behavior (pre-2.19.0 contract). The LLM sees
            // [{...}] exactly like before the wrapper was introduced.
            // Scalars (bool, int, float): wrap in {"result": <value>} so the
            // LLM can read false/0/true meaningfully instead of seeing "" or
            // "0", which would be indistinguishable from an empty response.
            if (is_array($result)) {
                $coerced = self::safeJsonEncode($result, '[]');
            } else {
                $coerced = self::safeJsonEncode(['result' => $result], '{"result":null}');
            }
            $this->history->recordResult($hash, $coerced);
            return $coerced;
        }

        $this->history->recordResult($hash, $result);
        return $result;
    }
}
