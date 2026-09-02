<?php

declare(strict_types=1);

namespace App\Chat;

enum CommandApprovalMode: string
{
    /** 每条命令均须人工审批（默认）。 */
    case AlwaysApprove = 'always_approve';

    /** 按策略：AutoRun 自动执行，RequireApproval 须审批，Deny 拒绝。 */
    case Policy = 'policy';

    /** 全部自动执行，跳过审批（含策略 Deny）。 */
    case ForceAuto = 'force_auto';

    public function label(): string
    {
        return match ($this) {
            self::AlwaysApprove => '逐条审批',
            self::Policy => '按策略执行',
            self::ForceAuto => '全部自动执行',
        };
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }

    public static function fromMixed(mixed $value, self $default = self::AlwaysApprove): self
    {
        return self::tryFromMixed($value) ?? $default;
    }
}
