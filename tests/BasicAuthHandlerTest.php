<?php

declare(strict_types=1);

namespace App\Tests;

use App\Middleware\BasicAuthHandler;
use App\Security\BasicAuthCookie;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

final class BasicAuthHandlerTest extends TestCase
{
    public function testAllowsPublicPathWithoutCredentials(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $handler(
            new ServerRequest('GET', 'http://localhost/health'),
            static fn () => new Response(200, [], 'ok'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsLogoutPathWithoutCredentials(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret', 'Web SSH', ['/health', '/logout']);
        $response = $handler(
            new ServerRequest('GET', 'http://localhost/logout'),
            static fn () => new Response(200, [], 'ok'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRedirectsHomeToLoginWithoutCredentials(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $handler(
            new ServerRequest('GET', 'http://localhost/', ['Accept' => ['text/html']]),
            static fn () => new Response(200, [], 'ok'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testRejectsMissingAuthorizationOnApi(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $handler(
            new ServerRequest('GET', 'http://localhost/api/me'),
            static fn () => new Response(200, [], 'ok'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAcceptsValidCredentialsAndSetsAuthUser(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $handler(
            new ServerRequest('GET', 'http://localhost/', [
                'Authorization' => ['Basic ' . base64_encode('admin:secret')],
            ]),
            static function ($request) {
                self::assertSame('admin', $request->getAttribute('auth_user'));

                return new Response(200, [], 'ok');
            },
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsValidAuthCookieAndSetsAuthUser(): void
    {
        BasicAuthCookie::configure(false, '0123456789abcdef0123456789abcdef');
        $issued = BasicAuthCookie::attach(new Response(200), 'admin', 3600);
        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $issued->getHeaderLine('Set-Cookie'), $matches);

        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $handler(
            new ServerRequest('GET', 'http://localhost/', [
                'Cookie' => [BasicAuthCookie::NAME . '=' . rawurldecode($matches[1])],
            ]),
            static function ($request) {
                self::assertSame('admin', $request->getAttribute('auth_user'));

                return new Response(200, [], 'ok');
            },
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
