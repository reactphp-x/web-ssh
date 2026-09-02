<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Ssh\OrchestratorToolContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class GetCommandContextTool extends Tool
{
    public const NAME = 'get_command_context';

    public function __construct(private readonly int $aiSessionId)
    {
        parent::__construct(
            self::NAME,
            '读取指定主机上最近 AI 命令输出（只读，无需用户批准）。',
        );
    }

    public function getAiSessionId(): int
    {
        return $this->aiSessionId;
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('host_id', PropertyType::INTEGER, '主机 ID（来自 list_hosts）', true),
            new ToolProperty('max_chars', PropertyType::INTEGER, '最近字符数上限（默认 4000）', false),
        ];
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(int $host_id, ?int $max_chars = null): string
    {
        OrchestratorToolContext::useSession($this->aiSessionId);

        $maxChars = max(500, min(8000, $max_chars ?? 4000));
        $bridge = OrchestratorToolContext::execBridge();
        $context = $bridge->getRecentOutput(
            $this->aiSessionId,
            $host_id,
            $maxChars,
        );

        return ToolJson::encode([
            'ok' => true,
            'host_id' => $host_id,
            'context' => $context,
        ]);
    }
}
