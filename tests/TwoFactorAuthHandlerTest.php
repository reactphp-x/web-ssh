<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\DatabaseConfig;
use App\Database\SqliteClientFactory;
use App\Middleware\TwoFactorAuthHandler;
use App\Repository\TwoFactorRepository;
use App\Repository\TwoFactorSessionRepository;
use App\Security\SecretCipher;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use function React\Async\await;

final class TwoFactorAuthHandlerTest extends TestCase
{
    private string $dbPath;
    private TwoFactorAuthHandler $middleware;
    private SecretCipher $cipher;

    protected function setUp(): void
    {
        SqliteClientFactory::reset();
        $this->dbPath = sys_get_temp_dir() . '/web-ssh-2fa-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->cipher = SecretCipher::fromAppKey('base64:' . base64_encode(str_repeat('a', 32)));

        $db = new \SQLite3($this->dbPath);
        $db->exec('CREATE TABLE two_factor_auth (username TEXT PRIMARY KEY, label TEXT NOT NULL, encrypted_secret TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE two_factor_pending (username TEXT PRIMARY KEY, label TEXT NOT NULL, encrypted_secret TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE two_factor_sessions (token TEXT PRIMARY KEY, username TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $db->close();

        $client = SqliteClientFactory::get(new DatabaseConfig($this->dbPath, false));
        $this->middleware = new TwoFactorAuthHandler(
            new TwoFactorRepository($client, $this->cipher),
            new TwoFactorSessionRepository($client),
        );
    }

    protected function tearDown(): void
    {
        SqliteClientFactory::reset();
        if (isset($this->dbPath) && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testAllowsExemptApiMeWithoutTwoFactorSession(): void
    {
        $response = $this->await(($this->middleware)(
            $this->request('/api/me', 'admin'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBlocksProtectedApiWithoutTwoFactorSessionWhenNotConfigured(): void
    {
        $response = $this->await(($this->middleware)(
            $this->request('/api/hosts', 'admin'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('two_factor_required', $payload['code']);
        self::assertFalse($payload['configured']);
    }

    public function testBlocksProtectedApiWithoutTwoFactorSessionWhenConfigured(): void
    {
        $this->seedTwoFactor('admin', 'Phone');

        $response = $this->await(($this->middleware)(
            $this->request('/api/hosts', 'admin'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('two_factor_required', $payload['code']);
        self::assertTrue($payload['configured']);
    }

    public function testAllowsProtectedApiWithValidSessionCookie(): void
    {
        $this->seedTwoFactor('admin', 'Phone');
        $token = str_repeat('a', 64);
        $this->seedSession($token, 'admin');

        $response = $this->await(($this->middleware)(
            $this->request('/api/hosts', 'admin', 'web_ssh_2fa=' . $token),
            static function ($request) {
                self::assertTrue($request->getAttribute('2fa_verified'));

                return new Response(200, [], 'ok');
            },
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsApiMeWithValidSessionCookie(): void
    {
        $this->seedTwoFactor('admin', 'Phone');
        $token = str_repeat('c', 64);
        $this->seedSession($token, 'admin');

        $response = $this->await(($this->middleware)(
            $this->request('/api/me', 'admin', 'web_ssh_2fa=' . $token),
            static function ($request) {
                self::assertTrue($request->getAttribute('2fa_verified'));

                return new Response(200, [], 'ok');
            },
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSkipsAnonymousUser(): void
    {
        $response = $this->await(($this->middleware)(
            $this->request('/api/hosts', 'anonymous'),
            static fn () => new Response(200, [], 'ok'),
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    private function seedTwoFactor(string $username, string $label): void
    {
        $db = new \SQLite3($this->dbPath);
        $db->exec(sprintf(
            "INSERT INTO two_factor_auth (username, label, encrypted_secret) VALUES ('%s', '%s', '%s')",
            \SQLite3::escapeString($username),
            \SQLite3::escapeString($label),
            \SQLite3::escapeString($this->cipher->encrypt('JBSWY3DPEHPK3PXP')),
        ));
        $db->close();
    }

    private function seedSession(string $token, string $username): void
    {
        $db = new \SQLite3($this->dbPath);
        $db->exec(sprintf(
            "INSERT INTO two_factor_sessions (token, username, expires_at) VALUES ('%s', '%s', datetime('now', '+1 hour'))",
            \SQLite3::escapeString($token),
            \SQLite3::escapeString($username),
        ));
        $db->close();
    }

    private function request(string $path, string $username, string $cookie = ''): ServerRequest
    {
        $headers = $cookie !== '' ? ['Cookie' => [$cookie]] : [];
        $request = new ServerRequest('GET', 'http://localhost' . $path, $headers);

        return $request->withAttribute('auth_user', $username);
    }

    private function await(mixed $promise): Response
    {
        if ($promise instanceof Response) {
            return $promise;
        }

        $result = await($promise);
        self::assertInstanceOf(Response::class, $result);

        return $result;
    }
}
