<?php

declare(strict_types=1);

namespace App\Config;

use ReactphpX\Framework\Environment;

final class AuthSessionConfig
{
    private const DEFAULT_TTL = 14400;

    private const DEFAULT_RENEW_INTERVAL = 1800;

    public function __construct(
        private readonly int $ttl,
        private readonly int $renewInterval,
    ) {
    }

    public static function load(Environment $env): self
    {
        $ttl = max(300, $env->int('AUTH_SESSION_TTL', self::DEFAULT_TTL));
        $renewInterval = max(
            60,
            min($ttl - 60, $env->int('AUTH_SESSION_RENEW_INTERVAL', self::DEFAULT_RENEW_INTERVAL)),
        );

        return new self($ttl, $renewInterval);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function renewInterval(): int
    {
        return $this->renewInterval;
    }
}
