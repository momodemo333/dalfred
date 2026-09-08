<?php

declare(strict_types=1);

namespace Dalfred\Chat;

use Dalfred\Chat\ContentBlocks\AttachmentMetaContent;
use Dalfred\Chat\ToolPayloadTruncator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ChatHistoryException;

/**
 * Extended SQLChatHistory that sanitizes a persisted history on load so it
 * passes NeuronAI v3's HistoryTrimmer::validateAlternation() check.
 *
 * Four failure modes are repaired:
 *
 *  1. Orphan tool messages. When a conversation is interrupted during tool
 *     execution (PHP timeout, server crash, ToolRunsExceededException), the
 *     persisted history may contain a ToolCallMessage without its corresponding
 *     ToolResultMessage. The Anthropic API rejects this with "tool_use ids were
 *     found without tool_result" 400 errors, and the v3 trimmer throws on the
 *     next trim. We remove any ToolCallMessage not immediately followed by a
 *     ToolResultMessage, and any ToolResultMessage not preceded by a
 *     ToolCallMessage (orphans can be anywhere in history because the user may
 *     have continued sending messages after the interruption).
 *
 *  2. Broken user/assistant alternation. When a tool failure crashed the run
 *     before any assistant message was persisted (e.g. a PHP TypeError raised
 *     inside Tool::getResult() before 2.15.1 caught it as \Throwable), the
 *     history ends up with two USER messages in a row. The trimmer then throws
 *     `Invalid message sequence at position N: expected role assistant, got
 *     user`, blocking every subsequent chat() call on that thread. We insert a
 *     synthetic AssistantMessage placeholder between consecutive same-role
 *     messages so alternation is restored without losing the user's question
 *     from the UI history. Same fix for hypothetical assistant/assistant pairs.
 *
 *  3. Trailing orphan user message. NeuronAI persists the user message before
 *     running inference; if inference fails (invalid API key, network error,
 *     provider 4xx, ...) no assistant message is saved and the thread ends on
 *     an unanswered user message. On the next chat() call NeuronAI appends the
 *     current user message, producing [user, user] and the same trimmer
 *     exception — but repairAlternation() can't see it because the duplicate
 *     only forms after load(). We drop the trailing orphan user instead of
 *     inserting a placeholder (an unanswered question carries no context worth
 *     keeping and a fake tail message would waste tokens every turn).
 *
 *  4. Tool arrays that are not PHP lists. Some provider paths hand back a tools
 *     array whose first key is not 0 (Gemini's HandleChat filters the response
 *     parts with a key-preserving array_filter). Such an array serialises to a
 *     JSON object instead of a JSON array, and Gemini rejects the resulting
 *     request with `Invalid JSON payload received. Unknown name "1" at
 *     'contents[N].parts'`. Because the message is persisted, every later turn
 *     re-sends it and gets the same 400 — the thread answers an error to every
 *     question, exactly like case 2 but through a different route. See
 *     ToolListNormalizer for the full analysis.
 *
 * Whenever the sanitizer changes the history, it persists the cleaned version
 * back to the database via setMessages(), so the corruption is healed once
 * rather than re-evaluated on every load.
 *
 * The v2 trimHistory() override has been removed: v3 delegates trimming to
 * HistoryTrimmer (an injectable component) and natively enforces valid
 * alternation, so the safety net is no longer needed there.
 */
class SafeSQLChatHistory extends SQLChatHistory
{
    /** Fallback texts, in English, used when the caller injects no translation. */
    public const DEFAULT_LOST_ANSWER_TEXT = '[Previous answer lost — a technical error interrupted the processing of your previous message.]';
    public const DEFAULT_LOST_QUESTION_TEXT = '[Missing user message restored to preserve the conversation alternation.]';

    private ToolPayloadTruncator $truncator;

    /**
     * Placeholder texts are injected rather than translated here: this class is
     * namespaced service code and must not reach for Dolibarr globals. The
     * caller resolves them with $langs so the user reads them in their own
     * language — see actions_dalfred / the chat endpoints.
     */
    private string $lostAnswerText;

    private string $lostQuestionText;

    public function __construct(
        string $thread_id,
        \PDO $pdo,
        string $table = 'chat_history',
        int $contextWindow = 50000,
        ?ToolPayloadTruncator $truncator = null,
        ?string $lostAnswerText = null,
        ?string $lostQuestionText = null
    ) {
        // Initialize before parent::__construct() because the parent calls load(),
        // which may call setMessages() (our override) for history repairs.
        $this->truncator = $truncator ?? new ToolPayloadTruncator(8000);
        $this->lostAnswerText = $lostAnswerText ?? self::DEFAULT_LOST_ANSWER_TEXT;
        $this->lostQuestionText = $lostQuestionText ?? self::DEFAULT_LOST_QUESTION_TEXT;
        parent::__construct($thread_id, $pdo, $table, $contextWindow);
    }

    protected function load(): void
    {
        parent::load();

        if ($this->history === []) {
            return;
        }

        $cleaned = [];
        $count = count($this->history);

        // Repair #4 (see class docblock). Runs first: a holed tools array is a
        // property of individual messages, independent of their sequence, and
        // healing it here means the cleaned history is persisted back below in
        // the same write as any alternation repair.
        $changed = ToolListNormalizer::normalizeAll($this->history);

        for ($i = 0; $i < $count; $i++) {
            $message = $this->history[$i];

            if ($message instanceof ToolCallMessage) {
                $next = ($i + 1 < $count) ? $this->history[$i + 1] : null;
                if ($next instanceof ToolResultMessage) {
                    $cleaned[] = $message;
                    $cleaned[] = $next;
                    $i++;
                } else {
                    $changed = true;
                }
            } elseif ($message instanceof ToolResultMessage) {
                $changed = true;
            } else {
                $cleaned[] = $message;
            }
        }

        // Second pass: rebuild a history that satisfies the v3 trimmer's
        // alternation rules — including the turn left open at the tail.
        $finalHistory = $this->repairAlternation($cleaned, $changed);

        if ($changed) {
            $this->history = $finalHistory;
            $this->setMessages($this->history);
        }
    }

    /**
     * Rebuild a history that satisfies HistoryTrimmer::validateAlternation().
     *
     * This method MUST mirror that validator's state machine exactly. NeuronAI
     * runs the validator on the WHOLE history at every addMessage() call, so a
     * single invalid position anywhere bricks the thread forever with
     * "Invalid message sequence at position N: expected role X, got Y" — every
     * subsequent message fails, which is what the user experiences as "the chat
     * answers an error to everything".
     *
     * The validator's rules, which the loop below reproduces:
     *  - it starts out expecting a USER message (so a leading assistant message
     *    is invalid);
     *  - a ToolResultMessage means an ASSISTANT message must come next;
     *  - a ToolCallMessage (ASSISTANT role) means a USER message may come next;
     *  - regular messages must strictly alternate.
     *
     * A previous implementation reset the expected role to "anything" after a
     * tool message. That let two real corruptions through, both observed in
     * production: a turn interrupted after the tool result but before the
     * assistant answer (PHP timeout on a long generation), and a history ending
     * on an unanswered user message with a legitimate assistant message before
     * it. Neither was repaired, and the thread was lost.
     *
     * Repair strategy: insert a placeholder of the missing role rather than
     * dropping messages, so the persisted history keeps matching what the user
     * sees in the chat window, and so a tool call that really did run (a file
     * that was really created) stays in context. The pass is idempotent: a
     * repaired history produces no new placeholder on the next load.
     *
     * @param Message[] $messages
     * @param bool      $changed  set to true when at least one placeholder is inserted
     * @return Message[]
     */
    private function repairAlternation(array $messages, bool &$changed): array
    {
        if ($messages === []) {
            return $messages;
        }

        $repaired = [];
        $expectingUser = true;

        foreach ($messages as $message) {
            // Pass 1 above guarantees a tool result is preceded by its tool
            // call. What must follow is the assistant's answer.
            if ($message instanceof ToolResultMessage) {
                $repaired[] = $message;
                $expectingUser = false;
                continue;
            }

            // A tool call carries the ASSISTANT role; what follows is either its
            // tool result or the next user message.
            if ($message instanceof ToolCallMessage) {
                $repaired[] = $message;
                $expectingUser = true;
                continue;
            }

            $role = $message->getRole();
            $expectedRole = $expectingUser ? MessageRole::USER->value : MessageRole::ASSISTANT->value;

            if ($role !== $expectedRole) {
                $repaired[] = $this->makePlaceholder($expectingUser ? MessageRole::USER : MessageRole::ASSISTANT);
                $changed = true;
                $expectingUser = !$expectingUser;
            }

            $repaired[] = $message;
            $expectingUser = !$expectingUser;
        }

        // NOTE: a history ending mid-turn (on an unanswered user message, or on a
        // tool result whose assistant answer never reached the database) is left
        // as-is on purpose. It is still valid on its own, and at load() time we
        // cannot tell a dead turn from a turn currently running in async mode —
        // closing it here would corrupt a live conversation. The repair happens
        // in addMessage(), where the incoming message proves the turn is over.
        return $repaired;
    }

    /**
     * Repair the alternation before the new message lands in the history.
     *
     * load() deliberately leaves a turn left open at the tail alone, because it
     * cannot distinguish a dead turn from one still running in async mode. Here
     * we can: a new message arriving proves the previous turn is over. If that
     * turn never got its assistant answer — PHP timeout, fatal error, provider
     * hang up after the tool result — the missing message is materialised now,
     * before NeuronAI's trimmer validates the sequence and throws.
     *
     * This is also what heals threads already bricked in production: the next
     * message the user sends repairs the history in place.
     *
     * @throws ChatHistoryException
     */
    public function addMessage(Message $message): ChatHistoryInterface
    {
        // Repair #4 (see class docblock): the provider may hand us a tool
        // message whose tools array is not a list. Fixing it here — before it
        // is appended, persisted, and re-sent on every later turn — is what
        // stops the corruption from ever reaching the database.
        ToolListNormalizer::normalize($message);

        $candidate = $this->history;
        $candidate[] = $message;

        $changed = false;
        $repaired = $this->repairAlternation($candidate, $changed);

        if ($changed) {
            // Drop the incoming message again: parent::addMessage() appends it.
            array_pop($repaired);
            $this->history = $repaired;
        }

        return parent::addMessage($message);
    }

    /**
     * Build the synthetic message inserted to restore the alternation.
     *
     * AssistantMessage is used for both roles because it is the only concrete
     * Message subclass accepting an explicit role; the role passed here is what
     * the trimmer and the providers actually read.
     */
    private function makePlaceholder(MessageRole $role): AssistantMessage
    {
        return new AssistantMessage(
            $role === MessageRole::ASSISTANT ? $this->lostAnswerText : $this->lostQuestionText,
            $role
        );
    }

    /**
     * Override to apply payload truncation before delegating the actual DB
     * write to the parent. The latest tool-call/result pair (if any) is
     * preserved intact — see ToolPayloadTruncator for the full rules.
     *
     * @param Message[] $messages
     */
    protected function setMessages(array $messages): void
    {
        // Last line of defence before the write: whatever route a message took
        // to get here, it must not be stored with a holed tools array.
        ToolListNormalizer::normalizeAll($messages);

        $latestPairIndex = $this->findLatestToolPairIndex($messages);
        $count = count($messages);

        for ($i = 0; $i < $count; $i++) {
            $isLatestPair = ($latestPairIndex !== null)
                && ($i === $latestPairIndex || $i === $latestPairIndex + 1);
            $this->truncator->truncateForPersistence($messages[$i], $isLatestPair);
        }

        parent::setMessages($messages);
    }

    /**
     * Return the index of the most recent ToolCallMessage in the latest
     * complete or in-progress tool pair, provided no AssistantMessage has
     * been appended after the pair (which would signal that the conversation
     * has moved on and the grace period is over).
     *
     * Rules:
     *  - A complete pair (ToolCallMessage immediately before ToolResultMessage)
     *    with NO AssistantMessage after the ToolResult is considered "latest".
     *  - A lone trailing ToolCallMessage (ToolResult not yet saved) is also
     *    treated as "latest" because NeuronAI calls setMessages after every
     *    addMessage, so the ToolResult may not be in the array yet.
     *  - Once an AssistantMessage has been persisted after the ToolResult, the
     *    grace period is over and this method returns null, triggering truncation.
     *
     * @param Message[] $messages
     */
    private function findLatestToolPairIndex(array $messages): ?int
    {
        $count = count($messages);

        // Scan backward to find the last ToolResultMessage.
        for ($i = $count - 1; $i >= 0; $i--) {
            $msg = $messages[$i];

            // If we encounter a plain AssistantMessage (not a ToolCallMessage, which
            // also extends AssistantMessage) before finding a ToolResult, the grace
            // period may be over — the agent has already replied to the tool result.
            if ($msg instanceof AssistantMessage && !($msg instanceof ToolCallMessage)) {
                // Look for a complete ToolCall/ToolResult pair before this
                // AssistantMessage — if found, grace period is over → return null.
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($messages[$j] instanceof ToolResultMessage) {
                        return null; // Grace period expired.
                    }
                }
                // No ToolResult before this AssistantMessage → no pair to protect.
                return null;
            }

            if (!($msg instanceof ToolResultMessage)) {
                continue;
            }

            // Found the latest ToolResultMessage. Check whether the preceding
            // message is a ToolCallMessage (complete pair).
            $prevIndex = $i - 1;
            if ($prevIndex >= 0 && $messages[$prevIndex] instanceof ToolCallMessage) {
                return $prevIndex;
            }
        }

        // No complete pair found. Check for a lone trailing ToolCallMessage.
        // This protects an in-progress pair: NeuronAI calls setMessages after
        // every addMessage, so when the ToolCallMessage is added, the
        // ToolResultMessage has not been persisted yet. Without this guard,
        // the lone ToolCallMessage would be truncated before its result arrives.
        $last = $count - 1;
        if ($last >= 0 && $messages[$last] instanceof ToolCallMessage) {
            return $last;
        }

        return null;
    }

    /**
     * Override to recognize our custom 'dalfred_attachment_meta' content blocks
     * before the parent's deserializer hits them with ContentBlockType::from()
     * (which would throw because that type is not in the enum).
     *
     * Plain text blocks, image blocks, etc. fall back to the parent.
     *
     * @param mixed $content
     */
    protected function deserializeContent(mixed $content): string|ContentBlockInterface|array|null
    {
        if (!is_array($content) || $content === []) {
            return parent::deserializeContent($content);
        }

        // Multi-block payload: dispatch ours, defer the rest to the parent.
        if (isset($content[0]['type'])) {
            $blocks = [];
            $delegated = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === AttachmentMetaContent::TYPE_DISCRIMINANT) {
                    $blocks[] = AttachmentMetaContent::fromArray($block);
                } else {
                    // Collect non-custom blocks and let the parent rebuild them.
                    $delegated[] = $block;
                }
            }
            if ($delegated !== []) {
                $rebuilt = parent::deserializeContent($delegated);
                if (is_array($rebuilt)) {
                    $blocks = array_merge($blocks, $rebuilt);
                } elseif ($rebuilt !== null) {
                    $blocks[] = $rebuilt;
                }
            }
            return $blocks === [] ? null : $blocks;
        }

        return parent::deserializeContent($content);
    }
}
