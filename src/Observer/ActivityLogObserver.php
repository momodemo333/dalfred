<?php

declare(strict_types=1);

namespace Dalfred\Observer;

use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\AgentError;

/**
 * Observes NeuronAI agent events and logs them to llx_dalfred_activity_log.
 */
class ActivityLogObserver implements ObserverInterface
{
    private \DoliDB $db;
    private int $userId;
    private int $entityId;
    private ?string $threadId;

    /** @var array<string, float> Start times for duration calculation */
    private array $timers = [];

    /** @var array<string,mixed> Run context, populated by setContext() */
    private array $context = [];

    /**
     * Set or extend run context (provider, model, iteration, …).
     * Persisted on inference_start and error events.
     */
    public function setContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1, ?string $threadId = null)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
        $this->threadId = $threadId;
    }

    public function setThreadId(?string $threadId): void
    {
        $this->threadId = $threadId;
        if ($threadId !== null) {
            $this->context = array_merge($this->context, ['thread_id' => $threadId]);
        }
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if ($data === null) {
            return;
        }

        match (true) {
            $data instanceof ToolCalling => $this->logToolCalling($data),
            $data instanceof ToolCalled => $this->logToolCalled($data),
            $data instanceof InferenceStart => $this->logInferenceStart($data),
            $data instanceof InferenceStop => $this->logInferenceStop($data),
            $data instanceof AgentError => $this->logError($data),
            default => null,
        };
    }

    private function logToolCalling(ToolCalling $data): void
    {
        $tool = $data->tool;
        $name = $tool->getName();
        $callId = $tool->getCallId() ?? $name;
        $this->timers['tool_' . $callId] = microtime(true);

        $inputs = json_encode($tool->getInputs(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $this->insert('tool_calling', $name, $inputs, null, null, null);
    }

    private function logToolCalled(ToolCalled $data): void
    {
        $tool = $data->tool;
        $name = $tool->getName();
        $callId = $tool->getCallId() ?? $name;

        $key = 'tool_' . $callId;
        $duration = isset($this->timers[$key])
            ? (int) ((microtime(true) - $this->timers[$key]) * 1000)
            : null;
        unset($this->timers[$key]);

        $inputs = json_encode($tool->getInputs(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        // Tool::getResult() is typed `: string` upstream but $result stays null
        // when the tool never reached setResult() — typically when
        // ToolRunsExceededException is raised by ToolNode::executeSingleTool
        // BEFORE $tool->execute() runs. The `finally` then emits 'tool-called'
        // anyway, and a TypeError here would mask the original exception and
        // crash the whole turn just to log it. Coerce defensively and surface
        // the cause so the activity_log entry is self-explanatory.
        try {
            $rawResult = $tool->getResult();
        } catch (\TypeError $e) {
            $rawResult = '[no result — tool execution did not complete (likely ToolRunsExceededException)]';
        }
        $result = mb_substr($rawResult, 0, 5000);

        $this->insert('tool_called', $name, $inputs, $result, $duration, null);
    }

    private function logInferenceStart(InferenceStart $data): void
    {
        $this->timers['inference'] = microtime(true);

        $this->insert('inference_start', null, null, null, null, null, null, null, null, $this->encodeContext());
    }

    private function logInferenceStop(InferenceStop $data): void
    {
        $duration = isset($this->timers['inference'])
            ? (int) ((microtime(true) - $this->timers['inference']) * 1000)
            : null;
        unset($this->timers['inference']);

        $this->insert('inference_stop', null, null, null, $duration, null);
    }

    private function logError(AgentError $data): void
    {
        $ex = $data->exception;

        $message = mb_substr($ex->getMessage(), 0, 5000);
        $class = mb_substr(get_class($ex), 0, 255);
        $file = mb_substr($ex->getFile() . ':' . $ex->getLine(), 0, 500);

        $trace = $ex->getTraceAsString();
        if (mb_strlen($trace) > 10000) {
            $trace = mb_substr($trace, 0, 10000) . "\n... [truncated]";
        }

        $this->insert('error', null, null, null, null, $message, $class, $file, $trace, $this->encodeContext());
    }

    private function encodeContext(): ?string
    {
        if (empty($this->context)) {
            return null;
        }
        $encoded = json_encode($this->context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return ($encoded !== false) ? mb_substr($encoded, 0, 2000) : null;
    }

    /**
     * Record a double-empty provider response event. Called by
     * DalfredChatNode when the empty-response retry also returned empty
     * content, after which a fallback message has been shown to the user.
     *
     * Single row per user-visible failure. Successful retries are NOT
     * logged here — they only emit a LOG_INFO syslog line. See
     * dev/2026-06-22-empty-response-guard-design.md § Q4-A.
     *
     * @param string      $provider     The active provider name (e.g. 'gemini')
     * @param string      $model        The active model (e.g. 'gemini-2.5-flash')
     * @param string|null $threadId     The conversation thread, when available
     * @param string[]    $stopReasons  Stop reasons of both empty attempts,
     *                                  e.g. ['STOP', 'STOP'] or ['STOP', 'UNKNOWN']
     */
    public function logEmptyProviderResponse(
        string $provider,
        string $model,
        ?string $threadId,
        array $stopReasons
    ): void {
        if ($threadId !== null && $this->threadId === null) {
            $this->setThreadId($threadId);
        }

        $message = "Provider {$provider} returned empty content twice in a row"
            . ($threadId !== null ? " on thread {$threadId}" : '')
            . ". Fallback message shown to user.";

        $context = json_encode(
            [
                'provider'     => $provider,
                'model'        => $model,
                'thread_id'    => $threadId,
                'stop_reasons' => array_values($stopReasons),
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($context === false) {
            $context = '{}';
        }

        $this->insert(
            'error',
            null,                       // tool_name
            null,                       // tool_inputs
            null,                       // tool_result
            null,                       // duration_ms
            $message,                   // error_message
            'EmptyProviderResponse',    // exception_class
            null,                       // exception_file
            null,                       // stack_trace
            $context
        );
    }

    private function insert(
        string $eventType,
        ?string $toolName,
        ?string $inputs,
        ?string $result,
        ?int $durationMs,
        ?string $errorMsg,
        ?string $exceptionClass = null,
        ?string $exceptionFile = null,
        ?string $stackTrace = null,
        ?string $context = null
    ): void {
        try {
            $now = dol_now();
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_activity_log"
                . " (entity, fk_user, thread_id, event_type, tool_name, tool_inputs, tool_result,"
                . " duration_ms, error_message, exception_class, exception_file, stack_trace, context, date_creation)"
                . " VALUES ("
                . (int) $this->entityId . ", "
                . (int) $this->userId . ", "
                . ($this->threadId !== null ? "'" . $this->db->escape($this->threadId) . "'" : "NULL") . ", "
                . "'" . $this->db->escape($eventType) . "', "
                . ($toolName !== null ? "'" . $this->db->escape($toolName) . "'" : "NULL") . ", "
                . ($inputs !== null ? "'" . $this->db->escape($inputs) . "'" : "NULL") . ", "
                . ($result !== null ? "'" . $this->db->escape($result) . "'" : "NULL") . ", "
                . ($durationMs !== null ? (int) $durationMs : "NULL") . ", "
                . ($errorMsg !== null ? "'" . $this->db->escape($errorMsg) . "'" : "NULL") . ", "
                . ($exceptionClass !== null ? "'" . $this->db->escape($exceptionClass) . "'" : "NULL") . ", "
                . ($exceptionFile !== null ? "'" . $this->db->escape($exceptionFile) . "'" : "NULL") . ", "
                . ($stackTrace !== null ? "'" . $this->db->escape($stackTrace) . "'" : "NULL") . ", "
                . ($context !== null ? "'" . $this->db->escape($context) . "'" : "NULL") . ", "
                . "'" . $this->db->idate($now) . "'"
                . ")";

            $this->db->query($sql);
        } catch (\Throwable $e) {
            // Never let the observer crash the agent. Best-effort logging only.
            \dol_syslog('[DALFRED] ActivityLogObserver insert failed: ' . $e->getMessage(), LOG_WARNING);
        }
    }
}
