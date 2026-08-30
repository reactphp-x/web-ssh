<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestAuth
{
    public static function username(ServerRequestInterface $request): string
    {
        return (string) ($request->getAttribute('auth_user') ?? 'anonymous');
    }

    public static function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $serverParams = $request->getServerParams();

        return (string) ($serverParams['REMOTE_ADDR'] ?? '');
    }
}
