<?php

declare(strict_types=1);

namespace App\Policy;

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

    public function approvalRequiredWithTrust(bool $sessionTrustEnabled): bool
    {
        return $this->action === PolicyAction::RequireApproval;
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
