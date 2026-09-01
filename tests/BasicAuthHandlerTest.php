<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AuthSessionConfig;
use App\Middleware\BasicAuthHandler;
use App\Security\BasicAuthCookie;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use function React\Async\await;

final class BasicAuthHandlerTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private function sessionConfig(): AuthSessionConfig
    {
        return new AuthSessionConfig(14400, 1800);
    }

    private function awaitResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        $response = await($result);
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }
    public function testAllowsPublicPathWithoutCredentials(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $this->awaitResponse($handler(
            new ServerRequest('GET', 'http://localhost/health'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsLogoutPathWithoutCredentials(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret', 'Web SSH', ['/health', '/logout']);
        $response = $this->awaitResponse($handler(
            new ServerRequest('GET', 'http://localhost/logout'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRedirectsHomeToLoginWithoutCredentials(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $this->awaitResponse($handler(
            new ServerRequest('GET', 'http://localhost/', ['Accept' => ['text/html']]),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testRejectsMissingAuthorizationOnApi(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $this->awaitResponse($handler(
            new ServerRequest('GET', 'http://localhost/api/me'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAcceptsValidCredentialsAndSetsAuthUser(): void
    {
        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $this->awaitResponse($handler(
            new ServerRequest('GET', 'http://localhost/', [
                'Authorization' => ['Basic ' . base64_encode('admin:secret')],
            ]),
            static function ($request) {
                self::assertSame('admin', $request->getAttribute('auth_user'));

                return new Response(200, [], 'ok');
            },
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsValidAuthCookieAndSetsAuthUser(): void
    {
        BasicAuthCookie::configure(false, '0123456789abcdef0123456789abcdef');
        $issued = BasicAuthCookie::attach(new Response(200), 'admin', 3600);
        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $issued->getHeaderLine('Set-Cookie'), $matches);

        $handler = new BasicAuthHandler('admin', 'secret');
        $response = $this->awaitResponse($handler(
            new ServerRequest('GET', 'http://localhost/', [
                'Cookie' => [BasicAuthCookie::NAME . '=' . rawurldecode($matches[1])],
            ]),
            static function ($request) {
                self::assertSame('admin', $request->getAttribute('auth_user'));

                return new Response(200, [], 'ok');
            },
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRenewsAuthCookieWhenNearExpiry(): void
    {
        BasicAuthCookie::configure(false, self::KEY, 14400);
        $issued = BasicAuthCookie::attach(new Response(200), 'admin', 1000);
        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $issued->getHeaderLine('Set-Cookie'), $matches);

        $handler = new BasicAuthHandler('admin', 'secret', 'Web SSH', ['/health'], null, $this->sessionConfig());
        $response = await($handler(
            new ServerRequest('GET', 'http://localhost/api/me', [
                'Cookie' => [BasicAuthCookie::NAME . '=' . rawurldecode($matches[1])],
            ]),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(BasicAuthCookie::NAME . '=', $response->getHeaderLine('Set-Cookie'));
    }

    public function testDoesNotRenewAuthCookieWithinThrottleWindow(): void
    {
        BasicAuthCookie::configure(false, self::KEY, 14400);
        $issued = BasicAuthCookie::attach(new Response(200), 'admin', 14400);
        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $issued->getHeaderLine('Set-Cookie'), $matches);

        $handler = new BasicAuthHandler('admin', 'secret', 'Web SSH', ['/health'], null, $this->sessionConfig());
        $response = await($handler(
            new ServerRequest('GET', 'http://localhost/api/me', [
                'Cookie' => [BasicAuthCookie::NAME . '=' . rawurldecode($matches[1])],
            ]),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }
}
