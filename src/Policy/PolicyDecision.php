<?php

declare(strict_types=1);

namespace App\Policy;

use App\Chat\CommandApprovalMode;

final readonly class PolicyDecision
{
    public function __construct(
        public PolicyAction $action,
        public string $reason,
        public ?string $matchedRule,
        public CommandInspection $inspection,
    ) {
    }

    public function requiresApproval(): bool
    {
        return $this->action === PolicyAction::RequireApproval;
    }

    public function approvalRequiredWithMode(CommandApprovalMode $mode): bool
    {
        return match ($mode) {
            CommandApprovalMode::ForceAuto => false,
            CommandApprovalMode::AlwaysApprove => $this->action !== PolicyAction::Deny,
            CommandApprovalMode::Policy => $this->action === PolicyAction::RequireApproval,
        };
    }

    public function shouldBypassDeny(CommandApprovalMode $mode): bool
    {
        return $mode === CommandApprovalMode::ForceAuto;
    }

    /**
     * @return array<string, mixed>
     */
    public function toUiPayload(): array
    {
        return [
            'action' => $this->action->value,
            'label' => $this->action->label(),
            'reason' => $this->reason,
            'matched_rule' => $this->matchedRule,
            'summary' => $this->inspection->summary(),
            'binaries' => $this->inspection->binaries(),
        ];
    }
}
