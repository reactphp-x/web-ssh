<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Ssh\OrchestratorToolContext;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Tools\ToolInterface;

final class OrchestratorRunSshCommandApproval extends ToolApproval
{
    public function __construct()
    {
        parent::__construct([
            OrchestratorRunSshCommandTool::class => static fn(array $args): bool => true,
        ]);
    }

    protected function toolRequiresApproval(ToolInterface $tool): bool
    {
        if ($tool instanceof OrchestratorRunSshCommandTool) {
            OrchestratorToolContext::useSession($tool->getAiSessionId());

            return RunSshCommandPolicyHelper::orchestratorToolRequiresApproval($tool->getInputs());
        }

        return parent::toolRequiresApproval($tool);
    }
}
