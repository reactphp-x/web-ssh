<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AuthSessionConfig;
use App\Config\DatabaseConfig;
use App\Database\SqliteClientFactory;
use App\Repository\TwoFactorSessionRepository;
use PHPUnit\Framework\TestCase;
use ReactphpX\Framework\Environment;
use function React\Async\await;

final class AuthSessionConfigTest extends TestCase
{
    public function testDefaultsToFourHourTtlAndThirtyMinuteRenewInterval(): void
    {
        $env = Environment::load(dirname(__DIR__), '.env.example');
        $config = AuthSessionConfig::load($env);

        self::assertSame(14400, $config->ttl());
        self::assertSame(1800, $config->renewInterval());
    }
}

final class TwoFactorSessionRepositoryTest extends TestCase
{
    private string $dbPath;
    private TwoFactorSessionRepository $repository;

    protected function setUp(): void
    {
        SqliteClientFactory::reset();
        $this->dbPath = sys_get_temp_dir() . '/web-ssh-2fa-repo-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new \SQLite3($this->dbPath);
        $db->exec('CREATE TABLE two_factor_sessions (
            token TEXT PRIMARY KEY,
            username TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->close();

        $client = SqliteClientFactory::get(new DatabaseConfig($this->dbPath, false));
        $this->repository = new TwoFactorSessionRepository($client);
    }

    protected function tearDown(): void
    {
        SqliteClientFactory::reset();
        if (isset($this->dbPath) && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testExtendUpdatesExpiresAtForValidSession(): void
    {
        $token = str_repeat('b', 64);
        await($this->repository->create('admin', $token, 600));

        $before = $this->readExpiresAt($token);
        await($this->repository->extend($token, 'admin', 7200));
        $after = $this->readExpiresAt($token);

        self::assertNotSame($before, $after);
        self::assertGreaterThan(strtotime($before), strtotime($after));
    }

    public function testExtendDoesNotUpdateExpiredSession(): void
    {
        $token = str_repeat('c', 64);
        $db = new \SQLite3($this->dbPath);
        $db->exec(sprintf(
            "INSERT INTO two_factor_sessions (token, username, expires_at) VALUES ('%s', 'admin', datetime('now', '-1 hour'))",
            \SQLite3::escapeString($token),
        ));
        $db->close();

        $before = $this->readExpiresAt($token);
        await($this->repository->extend($token, 'admin', 7200));

        self::assertSame($before, $this->readExpiresAt($token));
    }

    private function readExpiresAt(string $token): string
    {
        $db = new \SQLite3($this->dbPath);

        return (string) $db->querySingle(
            "SELECT expires_at FROM two_factor_sessions WHERE token = '" . \SQLite3::escapeString($token) . "'",
        );
    }
}
