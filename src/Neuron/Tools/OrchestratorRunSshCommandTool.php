<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Neuron\Agent\Tools\OrchestratorRunSshCommandPendingHandler;
use App\Ssh\CommandTimeoutResolver;
use App\Ssh\OrchestratorToolContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class OrchestratorRunSshCommandTool extends Tool
{
    public const NAME = 'run_ssh_command';

    public function __construct(private readonly int $aiSessionId)
    {
        parent::__construct(
            self::NAME,
            '在指定主机执行一条 shell 命令。写操作，执行前会暂停等待用户批准。'
            . '可通过 timeout_sec 为耗时命令指定超时秒数（未指定则用默认值，不得超过配置上限）。',
        );
        $this->setCallable(new OrchestratorRunSshCommandPendingHandler());
    }

    public function getAiSessionId(): int
    {
        return $this->aiSessionId;
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('host_id', PropertyType::INTEGER, '目标主机 ID（来自 list_hosts）', true),
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
    public function __invoke(int $host_id, string $command, string $reason, ?int $timeout_sec = null): string
    {
        OrchestratorToolContext::useSession($this->aiSessionId);

        $timeoutSec = CommandTimeoutResolver::resolve(
            $timeout_sec,
            OrchestratorToolContext::commandTimeout(),
            OrchestratorToolContext::commandTimeoutMax(),
        );

        return ToolJson::encode(OrchestratorRunSshCommandRunner::run(
            $this->aiSessionId,
            $host_id,
            $command,
            $reason,
            $timeoutSec,
        ));
    }
}
