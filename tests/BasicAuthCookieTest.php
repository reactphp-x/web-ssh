<?php

declare(strict_types=1);

namespace App\Tests;

use App\Middleware\BasicAuthHandler;
use App\Security\BasicAuthCookie;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

final class BasicAuthCookieTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        BasicAuthCookie::configure(false, self::KEY);
    }

    public function testIssueAndReadRoundTrip(): void
    {
        $response = BasicAuthCookie::attach(new Response(200), 'admin', 3600);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString(BasicAuthCookie::NAME . '=', $cookie);

        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $cookie, $matches);
        $request = new ServerRequest('GET', 'http://localhost/', [
            'Cookie' => [BasicAuthCookie::NAME . '=' . rawurldecode($matches[1])],
        ]);

        self::assertSame('admin', BasicAuthCookie::read($request));
    }

    public function testRejectsTamperedCookie(): void
    {
        $response = BasicAuthCookie::attach(new Response(200), 'admin', 3600);
        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches);
        $value = rawurldecode($matches[1]) . 'x';
        $request = new ServerRequest('GET', 'http://localhost/', [
            'Cookie' => [BasicAuthCookie::NAME . '=' . $value],
        ]);

        self::assertNull(BasicAuthCookie::read($request));
        self::assertNull(BasicAuthCookie::readDetails($request));
    }

    public function testReadDetailsReturnsUsernameAndExpiry(): void
    {
        $response = BasicAuthCookie::attach(new Response(200), 'admin', 3600);
        preg_match('/' . preg_quote(BasicAuthCookie::NAME, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches);
        $request = new ServerRequest('GET', 'http://localhost/', [
            'Cookie' => [BasicAuthCookie::NAME . '=' . rawurldecode($matches[1])],
        ]);

        $details = BasicAuthCookie::readDetails($request);

        self::assertNotNull($details);
        self::assertSame('admin', $details['username']);
        self::assertGreaterThan(time(), $details['expiresAt']);
    }
}
