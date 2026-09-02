<?php

declare(strict_types=1);

namespace App\Config;

use ReactphpX\Framework\Environment;

final class TwoFactorConfig
{
    private function __construct(
        private readonly bool $enabled,
    ) {
    }

    public static function load(Environment $env, bool $basicAuthEnabled): self
    {
        if (!$basicAuthEnabled) {
            return new self(false);
        }

        return new self(filter_var(
            $env->nullableString('TWO_FACTOR_ENABLED') ?? 'true',
            FILTER_VALIDATE_BOOL,
        ));
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }
}
