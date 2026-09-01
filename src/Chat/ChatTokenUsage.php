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
use function strtolower;

final class ChatTokenUsage
{
    /**
     * @return array{
     *     context_used: int,
     *     context_window: int,
     *     context_percent: int,
     *     cached_input_tokens: int,
     *     cache_hit_percent: int,
     *     session_total_tokens: int,
     *     session_cached_input_tokens: int,
     *     session_uncached_input_tokens: int,
     *     session_output_tokens: int,
     *     session_cache_hit_percent: int
     * }
     */
    public static function summarize(ChatFileHistory $history, ChatSettings $settings): array
    {
        $contextWindow = $settings->contextWindow();
        $messages = $history->getMessages();
        $provider = $settings->provider();

        $trimmer = new HistoryTrimmer();
        $trimmer->trim($messages, $contextWindow);
        $contextUsed = $trimmer->getTotalTokens();

        $percent = $contextWindow > 0
            ? min(100, (int) round($contextUsed / $contextWindow * 100))
            : 0;

        $inference = self::lastInferenceUsage($history, $messages);
        $session = self::sessionUsage($history, $provider);

        return [
            'context_used' => $contextUsed,
            'context_window' => $contextWindow,
            'context_percent' => $percent,
            'cached_input_tokens' => $inference['cached_input_tokens'],
            'cache_hit_percent' => self::cacheHitPercent(
                $inference['input_tokens'],
                $inference['cached_input_tokens'],
                $provider,
            ),
            'session_total_tokens' => $session['total_tokens'],
            'session_cached_input_tokens' => $session['cached_input_tokens'],
            'session_uncached_input_tokens' => $session['uncached_input_tokens'],
            'session_output_tokens' => $session['output_tokens'],
            'session_cache_hit_percent' => $session['cache_hit_percent'],
        ];
    }

    public static function cacheHitPercent(int $inputTokens, int $cachedInputTokens, string $provider): int
    {
        if ($cachedInputTokens <= 0) {
            return 0;
        }

        $denominator = strtolower($provider) === 'anthropic'
            ? $inputTokens + $cachedInputTokens
            : $inputTokens;

        if ($denominator <= 0) {
            return 0;
        }

        return min(100, (int) round($cachedInputTokens / $denominator * 100));
    }

    public static function uncachedInputTokens(int $inputTokens, int $cachedInputTokens, string $provider): int
    {
        if (strtolower($provider) === 'anthropic') {
            return max(0, $inputTokens);
        }

        return max(0, $inputTokens - $cachedInputTokens);
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
     * @return array{
     *     total_tokens: int,
     *     cached_input_tokens: int,
     *     uncached_input_tokens: int,
     *     output_tokens: int,
     *     cache_hit_percent: int
     * }
     */
    private static function sessionUsage(ChatFileHistory $history, string $provider): array
    {
        $cached = 0;
        $uncached = 0;
        $output = 0;

        foreach (self::collectInferenceUsages($history) as $usage) {
            $input = max(0, (int) ($usage['input_tokens'] ?? 0));
            $cachedInput = max(0, (int) ($usage['cached_input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));

            $cached += $cachedInput;
            $uncached += self::uncachedInputTokens($input, $cachedInput, $provider);
            $output += $outputTokens;
        }

        $inputTotal = $cached + $uncached;

        return [
            'total_tokens' => $inputTotal + $output,
            'cached_input_tokens' => $cached,
            'uncached_input_tokens' => $uncached,
            'output_tokens' => $output,
            'cache_hit_percent' => $inputTotal > 0
                ? min(100, (int) round($cached / $inputTotal * 100))
                : 0,
        ];
    }

    /**
     * @return list<array{input_tokens: int, output_tokens: int, cached_input_tokens: int}>
     */
    private static function collectInferenceUsages(ChatFileHistory $history): array
    {
        $path = $history->storageFilePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }

        $usages = [];

        foreach ($raw as $message) {
            if (!is_array($message) || !isset($message['usage']) || !is_array($message['usage'])) {
                continue;
            }

            $role = (string) ($message['role'] ?? '');
            $type = (string) ($message['type'] ?? '');
            if ($role !== 'assistant' && $type !== 'tool_call') {
                continue;
            }

            $usages[] = [
                'input_tokens' => max(0, (int) ($message['usage']['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($message['usage']['output_tokens'] ?? 0)),
                'cached_input_tokens' => max(0, (int) ($message['usage']['cached_input_tokens'] ?? 0)),
            ];
        }

        return $usages;
    }

    /**
     * @param list<Message> $messages
     * @return array{input_tokens: int, cached_input_tokens: int}
     */
    private static function lastInferenceUsage(ChatFileHistory $history, array $messages): array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (!$message instanceof AssistantMessage && !$message instanceof ToolCallMessage) {
                continue;
            }

            $usage = $message->getUsage();
            if ($usage instanceof Usage) {
                if ($usage->cachedInputTokens > 0) {
                    return [
                        'input_tokens' => $usage->inputTokens,
                        'cached_input_tokens' => $usage->cachedInputTokens,
                    ];
                }

                break;
            }
        }

        return self::lastInferenceUsageFromRawFile($history);
    }

    /**
     * @return array{input_tokens: int, cached_input_tokens: int}
     */
    private static function lastInferenceUsageFromRawFile(ChatFileHistory $history): array
    {
        $usages = self::collectInferenceUsages($history);
        if ($usages === []) {
            return ['input_tokens' => 0, 'cached_input_tokens' => 0];
        }

        $last = $usages[array_key_last($usages)];

        return [
            'input_tokens' => $last['input_tokens'],
            'cached_input_tokens' => $last['cached_input_tokens'],
        ];
    }
}
