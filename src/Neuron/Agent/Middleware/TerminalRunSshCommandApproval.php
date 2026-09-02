<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Tools\RunSshCommandTool;
use App\Ssh\SshToolContext;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Tools\ToolInterface;

final class TerminalRunSshCommandApproval extends ToolApproval
{
    public function __construct()
    {
        parent::__construct([
            RunSshCommandTool::class => static fn(array $args): bool => true,
        ]);
    }

    protected function toolRequiresApproval(ToolInterface $tool): bool
    {
        if ($tool instanceof RunSshCommandTool) {
            SshToolContext::use($tool->getConnId());

            return RunSshCommandPolicyHelper::terminalToolRequiresApproval($tool->getInputs());
        }

        return parent::toolRequiresApproval($tool);
    }
}
