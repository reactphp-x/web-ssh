<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Neuron\Agent\Tools\RunSshCommandPendingHandler;
use App\Ssh\CommandTimeoutResolver;
use App\Ssh\SshToolContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class RunSshCommandTool extends Tool
{
    public const NAME = 'run_ssh_command';

    public function __construct(private readonly string $connId)
    {
        parent::__construct(
            self::NAME,
            '在已连接的 SSH 终端执行一条 shell 命令。写操作，执行前会暂停等待用户批准。'
            . '可通过 timeout_sec 为耗时命令指定超时秒数（未指定则用默认值，不得超过配置上限）。',
        );
        $this->setCallable(new RunSshCommandPendingHandler());
    }

    public function getConnId(): string
    {
        return $this->connId;
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('command', PropertyType::STRING, '要执行的 shell 命令', true),
            new ToolProperty('reason', PropertyType::STRING, '说明为何执行此命令', true),
            new ToolProperty(
                'timeout_sec',
                PropertyType::INTEGER,
                '命令超时秒数（可选；未指定则用默认；不得超过配置上限）',
                false,
            ),
        ];
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(string $command, string $reason, ?int $timeout_sec = null): string
    {
        $timeoutSec = CommandTimeoutResolver::resolve(
            $timeout_sec,
            SshToolContext::commandTimeout(),
            SshToolContext::commandTimeoutMax(),
        );

        return ToolJson::encode(RunSshCommandRunner::run($this->connId, $command, $reason, $timeoutSec));
    }
}
