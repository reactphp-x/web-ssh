<?php

declare(strict_types=1);

namespace App\Chat;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

final class ChatTimelineBuilder
{
    /**
     * @param callable(string): string $toolLabel
     * @param callable(string): string $toHtml
     * @return list<array<string, mixed>>
     */
    public static function build(ChatFileHistory $history, callable $toolLabel, callable $toHtml): array
    {
        try {
            $items = $history->getMessages();
        } catch (\Throwable) {
            return [];
        }

        /** @var list<array<string, mixed>> $timeline */
        $timeline = [];
        /** @var array<string, int> $pendingTools */
        $pendingTools = [];

        foreach ($items as $item) {
            if ($item instanceof ToolCallMessage) {
                $text = method_exists($item, 'getContent') ? trim((string) $item->getContent()) : '';
                if ($text !== '') {
                    $timeline[] = self::messageEntry(ChatUtf8::sanitize($text), MessageRole::ASSISTANT->value, $toHtml);
                }

                foreach ($item->getTools() as $tool) {
                    $payload = $tool->jsonSerialize();
                    $entry = self::toolEntry($payload, 'running', $toolLabel);
                    if (($payload['result'] ?? null) !== null && ($payload['result'] ?? '') !== '') {
                        $entry = self::mergeToolResult($entry, $payload);
                    }

                    $index = count($timeline);
                    $timeline[] = $entry;
                    $callId = $entry['callId'] ?? null;
                    if (is_string($callId) && $callId !== '') {
                        $pendingTools[$callId] = $index;
                    }
                }

                continue;
            }

            if ($item instanceof ToolResultMessage) {
                foreach ($item->getTools() as $tool) {
                    $payload = $tool->jsonSerialize();
                    $callId = $payload['callId'] ?? null;
                    if (is_string($callId) && $callId !== '' && isset($pendingTools[$callId])) {
                        $timeline[$pendingTools[$callId]] = self::mergeToolResult(
                            $timeline[$pendingTools[$callId]],
                            $payload,
                        );
                        continue;
                    }

                    $timeline[] = self::mergeToolResult(
                        self::toolEntry($payload, 'done', $toolLabel),
                        $payload,
                    );
                }

                continue;
            }

            $role = method_exists($item, 'getRole') ? (string) $item->getRole() : '';
            if (!in_array($role, [MessageRole::USER->value, MessageRole::ASSISTANT->value], true)) {
                continue;
            }

            $content = method_exists($item, 'getContent') ? trim((string) $item->getContent()) : '';
            if ($content === '') {
                continue;
            }
            $content = ChatUtf8::sanitize($content);

            $timeline[] = self::messageEntry(
                $content,
                $role,
                $toHtml,
                $item instanceof AssistantMessage
                    && StoppedMessageMetadata::isStopped($item->getMetadata('stopped')),
            );
        }

        return $timeline;
    }

    /**
     * @return array{kind: string, role: string, content: string, html: string, stopped?: bool}
     */
    private static function messageEntry(string $content, string $role, callable $toHtml, bool $stopped = false): array
    {
        $entry = [
            'kind' => 'message',
            'role' => $role,
            'content' => $content,
            'html' => $toHtml($content),
        ];
        if ($stopped) {
            $entry['stopped'] = true;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(string): string $toolLabel
     * @return array<string, mixed>
     */
    private static function toolEntry(array $payload, string $status, callable $toolLabel): array
    {
        $name = (string) ($payload['name'] ?? '');
        $inputs = $payload['inputs'] ?? [];
        if ($inputs instanceof \stdClass) {
            $inputs = (array) $inputs;
        }

        return [
            'kind' => 'tool',
            'callId' => $payload['callId'] ?? null,
            'name' => $name,
            'label' => $toolLabel($name),
            'inputs' => is_array($inputs) ? $inputs : [],
            'result' => null,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function mergeToolResult(array $entry, array $payload): array
    {
        $inputs = $payload['inputs'] ?? $entry['inputs'] ?? [];
        if ($inputs instanceof \stdClass) {
            $inputs = (array) $inputs;
        }
        if (is_array($inputs) && $inputs !== []) {
            $entry['inputs'] = $inputs;
        }

        $result = $payload['result'] ?? null;
        $entry['result'] = ChatUtf8::toolResult($result);
        $entry['status'] = 'done';

        return $entry;
    }
}
