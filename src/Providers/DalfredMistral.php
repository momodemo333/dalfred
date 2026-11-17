<?php

declare(strict_types=1);

namespace Dalfred\Providers;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\Mistral\Mistral;

use function implode;
use function is_array;
use function is_string;

/**
 * NeuronAI's Mistral provider, tolerant of reasoning models.
 *
 * Mistral Large 4 (and mistral-large-latest, which points to it since
 * November 2026) reasons before answering and returns `message.content` as an
 * array of blocks: a "thinking" block, then "text" blocks. NeuronAI 3.15 does
 * not expect that:
 *  - on a tool call it hands the array to TextContent, a TypeError that kills
 *    the turn ("must be of type string, array given");
 *  - on a plain answer it reads `choice.content`, which does not exist, instead
 *    of `choice.message.content`.
 * Every other Mistral model returns a string and goes through untouched.
 *
 * The array is reduced to its text blocks before NeuronAI sees it. The
 * reasoning is dropped: Dalfred never shows it, and Mistral does not need it
 * back in the history.
 */
class DalfredMistral extends Mistral
{
    protected function processChatResult(array $result): AssistantMessage
    {
        if (isset($result['choices'][0]['message']) && is_array($result['choices'][0]['message'])) {
            $content = $result['choices'][0]['message']['content'] ?? null;
            if (is_array($content)) {
                $result['choices'][0]['message']['content'] = self::textOf($content);
            } elseif ($content === null && ($result['choices'][0]['finish_reason'] ?? '') === 'tool_calls') {
                $result['choices'][0]['message']['content'] = '';
            }
        }

        return parent::processChatResult($result);
    }

    /**
     * Concatenated text of the "text" blocks of a Mistral content array.
     *
     * @param array<int, mixed> $blocks
     */
    private static function textOf(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }
}
