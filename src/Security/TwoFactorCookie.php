<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class TwoFactorCookie
{
    public const NAME = 'web_ssh_2fa';

    private static bool $secure = false;

    public static function configure(bool $secure): void
    {
        self::$secure = $secure;
    }

    public static function read(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Cookie');
        if ($header === '') {
            return null;
        }

        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if (!str_starts_with($part, self::NAME . '=')) {
                continue;
            }

            $value = rawurldecode(substr($part, strlen(self::NAME) + 1));
            if ($value !== '' && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    public static function attach(ResponseInterface $response, string $token): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', self::buildCookie($token));
    }

    public static function clear(ResponseInterface $response): ResponseInterface
    {
        $flags = 'Path=/; HttpOnly; SameSite=Strict; Max-Age=0';
        if (self::$secure) {
            $flags .= '; Secure';
        }

        return $response->withAddedHeader('Set-Cookie', self::NAME . '=; ' . $flags);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function buildCookie(string $token): string
    {
        $flags = 'Path=/; HttpOnly; SameSite=Strict';
        if (self::$secure) {
            $flags .= '; Secure';
        }

        return self::NAME . '=' . rawurlencode($token) . '; ' . $flags;
    }
}
