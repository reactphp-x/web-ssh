<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

use App\Neuron\Tools\RunSshCommandRunner;
use App\Neuron\Tools\ToolJson;
use App\Ssh\CommandTimeoutResolver;
use App\Ssh\SshToolContext;

final class RunSshCommandExecutorHandler
{
    public function __construct(private readonly string $connId)
    {
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
