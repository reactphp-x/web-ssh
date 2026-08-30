<?php

declare(strict_types=1);

namespace App\Chat;

use App\Neuron\Tools\RunSshCommandTool;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolInterface;
use stdClass;

final class StreamChunkMapper
{
    /**
     * @return array{event: string, data: array<string, mixed>}|null
     */
    public function map(mixed $chunk): ?array
    {
        if (is_string($chunk)) {
            return $chunk === '' ? null : ['event' => 'delta', 'data' => ['text' => $chunk]];
        }
        if (!is_object($chunk)) {
            return null;
        }

        if ($chunk instanceof ToolCallChunk) {
            return ['event' => 'tool', 'data' => $this->serializeTool($chunk->tool, 'call')];
        }
        if ($chunk instanceof ToolResultChunk) {
            return ['event' => 'tool', 'data' => $this->serializeTool($chunk->tool, 'result')];
        }
        if ($chunk instanceof ReasoningChunk) {
            return $chunk->content === '' ? null : ['event' => 'reasoning', 'data' => ['text' => $chunk->content]];
        }

        $text = '';
        if (isset($chunk->content) && is_string($chunk->content)) {
            $text = $chunk->content;
        } elseif (method_exists($chunk, 'getContent')) {
            $text = (string) $chunk->getContent();
        }
        if ($text === '') {
            return null;
        }

        return ['event' => 'delta', 'data' => ['text' => $text]];
    }

    private function toolName(mixed $tool): string
    {
        if (is_object($tool) && method_exists($tool, 'getName')) {
            return (string) $tool->getName();
        }

        return 'tool';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTool(ToolInterface $tool, string $phase): array
    {
        $payload = $tool->jsonSerialize();
        $name = (string) ($payload['name'] ?? $tool->getName());
        $inputs = $payload['inputs'] ?? [];
        if ($inputs instanceof stdClass) {
            $inputs = (array) $inputs;
        }

        $data = [
            'name' => $name,
            'label' => $this->toolLabel($name),
            'phase' => $phase,
            'callId' => $payload['callId'] ?? null,
            'inputs' => is_array($inputs) ? $inputs : [],
        ];

        if ($phase === 'result') {
            $result = $payload['result'] ?? null;
            if (is_string($result) && strlen($result) > 4000) {
                $result = substr($result, 0, 4000) . '…';
            }
            $data['result'] = $result;
        }

        return $data;
    }

    private function toolLabel(string $name): string
    {
        return match ($name) {
            RunSshCommandTool::NAME => '执行 SSH 命令',
            'ask_user' => '向用户提问',
            'get_terminal_context' => '读取终端输出',
            default => $name,
        };
    }
}
