<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Chat\CommandApprovalTrust;
use App\Policy\CommandPolicyEngine;
use App\Repository\HostRepository;

final readonly class OrchestratorToolContextState
{
    public function __construct(
        public SshExecBridge $execBridge,
        public HostRepository $hosts,
        public ?CommandPolicyEngine $policyEngine,
        public int $aiSessionId,
        public string $username,
        public int $commandTimeout,
        public int $commandTimeoutMax,
        public string $threadKey,
        public CommandApprovalTrust $approvalTrust,
    ) {
    }
}
