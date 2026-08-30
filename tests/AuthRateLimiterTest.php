<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\AuthRateLimitRepository;
use App\Service\AuthRateLimiter;
use PHPUnit\Framework\TestCase;
use function React\Async\await;

final class AuthRateLimiterTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/web-ssh-rate-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new \SQLite3($this->dbPath);
        $db->exec(
            'CREATE TABLE auth_rate_limits (
                bucket TEXT PRIMARY KEY,
                failures INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $db->close();
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testLocksAfterMaxFailures(): void
    {
        $limiter = $this->limiter(maxFailures: 3, lockSeconds: 60);

        await($limiter->recordFailure('2fa:admin'));
        await($limiter->recordFailure('2fa:admin'));
        $check = await($limiter->ensureAllowed('2fa:admin'));
        self::assertTrue($check['allowed']);

        await($limiter->recordFailure('2fa:admin'));
        $check = await($limiter->ensureAllowed('2fa:admin'));
        self::assertFalse($check['allowed']);
    }

    public function testClearResetsLock(): void
    {
        $limiter = $this->limiter(maxFailures: 1, lockSeconds: 60);
        await($limiter->recordFailure('2fa:admin'));
        self::assertFalse((await($limiter->ensureAllowed('2fa:admin')))['allowed']);

        await($limiter->clear('2fa:admin'));
        self::assertTrue((await($limiter->ensureAllowed('2fa:admin')))['allowed']);
    }

    private function limiter(int $maxFailures, int $lockSeconds): AuthRateLimiter
    {
        $db = new \SQLite3($this->dbPath);
        $database = new \App\Database\DatabaseClient($db);

        return new AuthRateLimiter(new AuthRateLimitRepository($database), $maxFailures, $lockSeconds);
    }
}
