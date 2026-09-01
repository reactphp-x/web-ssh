<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

use App\Neuron\Tools\OrchestratorRunSshCommandRunner;
use App\Neuron\Tools\ToolJson;
use App\Ssh\CommandTimeoutResolver;
use App\Ssh\OrchestratorToolContext;

final class OrchestratorRunSshCommandExecutorHandler
{
    public function __construct(private readonly int $aiSessionId)
    {
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(int $host_id, string $command, string $reason, ?int $timeout_sec = null): string
    {
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
