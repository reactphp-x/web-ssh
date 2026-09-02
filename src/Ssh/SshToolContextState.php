<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Chat\CommandApprovalTrust;
use App\Policy\CommandPolicyEngine;

final readonly class SshToolContextState
{
    public function __construct(
        public SshSessionBridge $bridge,
        public ?CommandPolicyEngine $policyEngine,
        public int $commandTimeout,
        public int $commandTimeoutMax,
        public string $threadKey,
        public string $username,
        public ?int $hostId,
        public ?int $hostGroupId,
        public CommandApprovalTrust $approvalTrust,
    ) {
    }
}
