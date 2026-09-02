<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

final class ConditionalRunSshCommandApproval
{
    public static function forSshAgent(): TerminalRunSshCommandApproval
    {
        return new TerminalRunSshCommandApproval();
    }

    public static function forOrchestrator(): OrchestratorRunSshCommandApproval
    {
        return new OrchestratorRunSshCommandApproval();
    }
}
