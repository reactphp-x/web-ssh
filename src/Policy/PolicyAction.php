<?php

declare(strict_types=1);

namespace App\Policy;

enum PolicyAction: string
{
    case AutoRun = 'auto_run';
    case RequireApproval = 'require_approval';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::AutoRun => '自动执行',
            self::RequireApproval => '需审批',
            self::Deny => '已拒绝',
        };
    }
}
