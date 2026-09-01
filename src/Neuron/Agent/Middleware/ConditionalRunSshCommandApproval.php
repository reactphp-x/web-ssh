<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Neuron\Tools\RunSshCommandTool;
use App\Ssh\OrchestratorToolContext;
use App\Ssh\SshToolContext;
use NeuronAI\Agent\Middleware\ToolApproval;

final class ConditionalRunSshCommandApproval
{
    public static function forSshAgent(): ToolApproval
    {
        return new ToolApproval([
            RunSshCommandTool::class => static fn(array $args): bool => SshToolContext::commandApprovalRequired(),
        ]);
    }

    public static function forOrchestrator(): ToolApproval
    {
        return new ToolApproval([
            OrchestratorRunSshCommandTool::class => static fn(array $args): bool => OrchestratorToolContext::commandApprovalRequired(),
        ]);
    }
}
