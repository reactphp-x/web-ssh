<?php

declare(strict_types=1);

namespace App\Security;

final class SessionRenewal
{
    public static function shouldRenew(int $currentExpiresAt, int $ttl, int $renewInterval): bool
    {
        return ($currentExpiresAt - time()) < ($ttl - $renewInterval);
    }
}
