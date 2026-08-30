<?php

declare(strict_types=1);

namespace App\Config;

use ReactphpX\Framework\Environment;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $path,
        public readonly bool $autoMigrate,
    ) {
    }

    public static function load(Environment $env): self
    {
        $path = trim((string) $env->nullableString('DB_PATH'));
        if ($path === '') {
            $path = $env->basePath() . '/storage/web_ssh.sqlite';
        } elseif (!str_starts_with($path, '/')) {
            $path = $env->basePath() . '/' . ltrim($path, '/');
        }

        return new self(
            path: $path,
            autoMigrate: $env->string('APP_ENV', 'production') === 'development'
                || filter_var($env->nullableString('DB_AUTO_MIGRATE'), FILTER_VALIDATE_BOOL),
        );
    }
}
