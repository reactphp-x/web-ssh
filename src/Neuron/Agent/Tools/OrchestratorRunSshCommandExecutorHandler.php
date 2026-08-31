<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

use App\Neuron\Tools\OrchestratorRunSshCommandRunner;
use App\Neuron\Tools\ToolJson;

final class OrchestratorRunSshCommandExecutorHandler
{
    public function __construct(private readonly int $aiSessionId)
    {
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(int $host_id, string $command, string $reason): string
    {
        return ToolJson::encode(OrchestratorRunSshCommandRunner::run(
            $this->aiSessionId,
            $host_id,
            $command,
            $reason,
        ));
    }
}
