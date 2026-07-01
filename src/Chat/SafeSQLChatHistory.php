<?php

declare(strict_types=1);

namespace Dalfred\Chat;

use Dalfred\Chat\ContentBlocks\AttachmentMetaContent;
use Dalfred\Chat\ToolPayloadTruncator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

/**
 * Extended SQLChatHistory that sanitizes a persisted history on load so it
 * passes NeuronAI v3's HistoryTrimmer::validateAlternation() check.
 *
 * Three failure modes are repaired:
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
    private ToolPayloadTruncator $truncator;

    public function __construct(
        string $thread_id,
        \PDO $pdo,
        string $table = 'chat_history',
        int $contextWindow = 50000,
        ?ToolPayloadTruncator $truncator = null
    ) {
        // Initialize before parent::__construct() because the parent calls load(),
        // which may call setMessages() (our override) for history repairs.
        $this->truncator = $truncator ?? new ToolPayloadTruncator(8000);
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
        $changed = false;

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

        // Second pass: repair broken user/assistant alternation by inserting a
        // synthetic AssistantMessage placeholder between consecutive same-role
        // regular messages. ToolCallMessage / ToolResultMessage pairs are
        // already validated by the first pass and are skipped here because
        // they have their own alternation rules in the v3 trimmer.
        $finalHistory = $this->repairAlternation($cleaned, $changed);

        // Third pass: drop a trailing orphan USER message left by a failed
        // inference. NeuronAI's AbstractChatHistory::addMessage() persists the
        // user message to DB *before* running inference; if inference then fails
        // (invalid API key, network error, provider 4xx, ...) no AssistantMessage
        // is ever saved and the thread is left ending on an unanswered user
        // message. On the next chat() call, load() rehydrates [user] and NeuronAI
        // appends the current user message → [user, user] → the v3 trimmer throws
        // "expected role assistant, got user" and the thread is bricked forever.
        // repairAlternation() cannot catch this because at load() time the history
        // holds a single, legitimately-valid user message; the duplicate only
        // appears after the current message is appended downstream. Dropping the
        // orphan here (load() runs in the SQLChatHistory constructor, i.e. on
        // every chat request) purges last turn's orphan before the new user
        // message is added, and heals already-corrupted threads in place.
        $finalHistory = $this->dropTrailingOrphanUser($finalHistory, $changed);

        if ($changed) {
            $this->history = $finalHistory;
            $this->setMessages($this->history);
        }
    }

    /**
     * Drop a trailing USER message left orphan by a failed inference.
     *
     * We remove the orphan rather than inserting an AssistantMessage placeholder
     * (the strategy repairAlternation() uses between two real exchanges) because:
     *  - an unanswered user message carries no useful context — the model
     *    receives the new question anyway on the next turn;
     *  - a fake "[previous answer lost…]" message at the tail would pollute the
     *    LLM context on every subsequent turn and waste tokens.
     * The placeholder only makes sense *between* two real exchanges, which is
     * why repairAlternation() is left untouched.
     *
     * @param Message[] $messages
     * @param bool      $changed  set to true when the orphan is removed
     * @return Message[]
     */
    private function dropTrailingOrphanUser(array $messages, bool &$changed): array
    {
        if ($messages === []) {
            return $messages;
        }

        $last = $messages[count($messages) - 1];

        // A trailing tool message is handled by the first pass; never treat it
        // as an orphan user here.
        if ($last instanceof ToolCallMessage || $last instanceof ToolResultMessage) {
            return $messages;
        }

        if ($last->getRole() === MessageRole::USER->value) {
            // Only drop the trailing user message if it is truly orphaned, i.e.
            // the message immediately before it is another USER message (or there
            // is no preceding message at all). When the preceding message is an
            // AssistantMessage or a ToolResultMessage, the trailing user is a
            // legitimate new turn awaiting a response and must be preserved.
            $count = count($messages);
            if ($count === 1) {
                // Single user message with no prior exchange — drop it.
                array_pop($messages);
                $changed = true;
            } else {
                $prev = $messages[$count - 2];
                $prevRole = ($prev instanceof ToolCallMessage || $prev instanceof ToolResultMessage)
                    ? null
                    : $prev->getRole();
                if ($prevRole === MessageRole::USER->value) {
                    // Two consecutive user messages → the last one is an orphan.
                    array_pop($messages);
                    $changed = true;
                }
                // If prevRole is ASSISTANT (or null / tool message), the trailing
                // user message is a valid new turn — leave it intact.
            }
        }

        return $messages;
    }

    /**
     * Insert AssistantMessage placeholders wherever two regular messages of
     * the same role appear consecutively. Tool messages are passed through
     * untouched because they have their own sequencing rules.
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
        $previousRole = null;

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
                $repaired[] = $message;
                // Tool messages do not participate in the user/assistant
                // alternation tracking — the next regular message starts a new
                // "previous role" baseline.
                $previousRole = null;
                continue;
            }

            $role = $message->getRole();

            if ($previousRole !== null && $role === $previousRole) {
                $placeholderRole = $role === MessageRole::USER->value
                    ? MessageRole::ASSISTANT
                    : MessageRole::USER;
                $placeholderText = $placeholderRole === MessageRole::ASSISTANT
                    ? '[Réponse précédente perdue — une erreur technique a interrompu le traitement de votre message précédent.]'
                    : '[Message utilisateur manquant restauré pour préserver l\'alternance de la conversation.]';
                $repaired[] = new AssistantMessage($placeholderText, $placeholderRole);
                $changed = true;
            }

            $repaired[] = $message;
            $previousRole = $role;
        }

        return $repaired;
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
