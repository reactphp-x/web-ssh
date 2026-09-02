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
        if ($this->action === PolicyAction::Deny) {
            return false;
        }

        // 策略「需审批」始终逐条审核，会话自动批准不可绕过。
        if ($this->action === PolicyAction::RequireApproval) {
            return true;
        }

        // AutoRun：未开启会话自动批准时仍须逐条审核。
        return !$sessionTrustEnabled;
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
