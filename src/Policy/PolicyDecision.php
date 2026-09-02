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

        // 未开启会话自动批准：每条命令都须人工审核（优先级高于策略 AutoRun）。
        if (!$sessionTrustEnabled) {
            return true;
        }

        return false;
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
