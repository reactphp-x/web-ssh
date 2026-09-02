<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Ssh\SshToolContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class GetTerminalContextTool extends Tool
{
    public const NAME = 'get_terminal_context';

    public function __construct(private readonly string $connId)
    {
        parent::__construct(
            self::NAME,
            '读取当前 SSH 终端最近输出（只读，无需用户批准）。',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('lines', PropertyType::INTEGER, '希望参考的最近字符数上限（默认 4000）', false),
        ];
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(?int $lines = null): string
    {
        SshToolContext::use($this->connId);

        $maxChars = max(500, min(8000, $lines ?? 4000));

        return ToolJson::encode([
            'ok' => true,
            'context' => SshToolContext::bridge()->getRecentOutput($this->connId, $maxChars),
        ]);
    }
}
