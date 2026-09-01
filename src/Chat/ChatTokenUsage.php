<?php

declare(strict_types=1);

namespace App\Chat;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;

use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function max;
use function min;
use function round;

final class ChatTokenUsage
{
    /**
     * @return array{
     *     context_used: int,
     *     context_window: int,
     *     context_percent: int,
     *     cached_input_tokens: int
     * }
     */
    public static function summarize(ChatFileHistory $history, ChatSettings $settings): array
    {
        $contextWindow = $settings->contextWindow();
        $messages = $history->getMessages();

        $trimmer = new HistoryTrimmer();
        $trimmer->trim($messages, $contextWindow);
        $contextUsed = $trimmer->getTotalTokens();

        $percent = $contextWindow > 0
            ? min(100, (int) round($contextUsed / $contextWindow * 100))
            : 0;

        return [
            'context_used' => $contextUsed,
            'context_window' => $contextWindow,
            'context_percent' => $percent,
            'cached_input_tokens' => self::lastCachedInputTokens($history, $messages),
        ];
    }


    /**
     * @param callable(string, array<string, mixed>): void $emit
     */
    public static function emit(ChatFileHistory $history, ChatSettings $settings, callable $emit): void
    {
        $emit('usage', self::summarize($history, $settings));
    }

    /**
     * Push usage after each completed LLM inference (tool-call decision).
     *
     * @param callable(string, array<string, mixed>): void $emit
     * @param array<string, mixed> $data
     */
    public static function emitIfInferenceFinished(
        ChatFileHistory $history,
        ChatSettings $settings,
        callable $emit,
        string $event,
        array $data,
    ): void {
        if ($event === 'tool' && ($data['phase'] ?? '') === 'call') {
            self::emit($history, $settings, $emit);
        }
    }

    /**
     * @param list<Message> $messages
     */
    private static function lastCachedInputTokens(ChatFileHistory $history, array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (!$message instanceof AssistantMessage && !$message instanceof ToolCallMessage) {
                continue;
            }

            $usage = $message->getUsage();
            if ($usage instanceof Usage) {
                if ($usage->cachedInputTokens > 0) {
                    return $usage->cachedInputTokens;
                }

                break;
            }
        }

        return self::lastCachedFromRawFile($history);
    }

    private static function lastCachedFromRawFile(ChatFileHistory $history): int
    {
        $path = $history->storageFilePath();
        if (!is_file($path)) {
            return 0;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return 0;
        }

        for ($i = count($raw) - 1; $i >= 0; $i--) {
            $message = $raw[$i];
            if (!is_array($message) || !isset($message['usage']) || !is_array($message['usage'])) {
                continue;
            }

            $role = (string) ($message['role'] ?? '');
            $type = (string) ($message['type'] ?? '');
            if ($role !== 'assistant' && $type !== 'tool_call') {
                continue;
            }

            return max(0, (int) ($message['usage']['cached_input_tokens'] ?? 0));
        }

        return 0;
    }
}
