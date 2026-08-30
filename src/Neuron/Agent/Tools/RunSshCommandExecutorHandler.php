<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

use App\Neuron\Tools\RunSshCommandRunner;
use App\Neuron\Tools\ToolJson;

final class RunSshCommandExecutorHandler
{
    public function __construct(private readonly string $connId)
    {
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(string $command, string $reason): string
    {
        return ToolJson::encode(RunSshCommandRunner::run($this->connId, $command, $reason));
    }
}
