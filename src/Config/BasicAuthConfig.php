<?php

declare(strict_types=1);

namespace App\Config;

use App\Middleware\BasicAuthHandler;
use App\Service\AuthRateLimiter;
use ReactphpX\Framework\Environment;

final class BasicAuthConfig
{
    private function __construct(
        private readonly ?BasicAuthHandler $handler,
    ) {
    }

    public static function load(Environment $env, ?AuthRateLimiter $loginRateLimiter = null): self
    {
        $username = trim($env->nullableString('BASIC_AUTH_USER') ?? '');
        $password = $env->nullableString('BASIC_AUTH_PASSWORD') ?? '';

        if ($username === '' || $password === '') {
            return new self(null);
        }

        $realm = trim($env->string('BASIC_AUTH_REALM', 'Web SSH'));
        $publicPaths = self::parsePublicPaths($env->nullableString('BASIC_AUTH_PUBLIC_PATHS'));

        return new self(new BasicAuthHandler($username, $password, $realm, $publicPaths, $loginRateLimiter));
    }

    public function handler(): ?BasicAuthHandler
    {
        return $this->handler;
    }

    /**
     * @return list<string>
     */
    private static function parsePublicPaths(?string $value): array
    {
        $paths = ['/health', '/logout', '/login', '/api/login'];
        if ($value === null || trim($value) === '') {
            return $paths;
        }

        return array_values(array_unique(array_merge(
            $paths,
            array_filter(array_map('trim', explode(',', $value))),
        )));
    }
}
