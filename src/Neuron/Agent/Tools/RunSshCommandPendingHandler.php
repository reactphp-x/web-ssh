<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

/**
 * run_ssh_command 等待用户审核时的占位 handler（WorkflowInterrupt 持久化前必须可序列化）。
 */
final class RunSshCommandPendingHandler
{
    public function __invoke(mixed ...$args): string
    {
        return 'Waiting for user approval.';
    }
}
