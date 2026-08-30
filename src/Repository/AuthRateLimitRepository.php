<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class AuthRateLimitRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    public function isLocked(string $bucket): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT 1 FROM auth_rate_limits
                 WHERE bucket = ? AND locked_until IS NOT NULL AND locked_until > datetime(\'now\')
                 LIMIT 1',
                [$bucket],
            )
            ->then(static fn ($result): bool => ($result->resultRows[0] ?? null) !== null);
    }

    public function recordFailure(string $bucket, int $maxFailures, int $lockSeconds): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT failures, locked_until FROM auth_rate_limits WHERE bucket = ? LIMIT 1',
                [$bucket],
            )
            ->then(function ($result) use ($bucket, $maxFailures, $lockSeconds): PromiseInterface {
                $row = $result->resultRows[0] ?? null;
                if ($row !== null && $this->isActiveLock((string) ($row['locked_until'] ?? ''))) {
                    return resolve(null);
                }

                $failures = (int) ($row['failures'] ?? 0) + 1;
                $lockExpr = $failures >= $maxFailures ? '+' . $lockSeconds . ' seconds' : null;

                if ($lockExpr === null) {
                    return $this->db->query(
                        'INSERT INTO auth_rate_limits (bucket, failures, locked_until, updated_at)
                         VALUES (?, ?, NULL, datetime(\'now\'))
                         ON CONFLICT(bucket) DO UPDATE SET
                            failures = excluded.failures,
                            locked_until = NULL,
                            updated_at = excluded.updated_at',
                        [$bucket, $failures],
                    );
                }

                return $this->db->query(
                    'INSERT INTO auth_rate_limits (bucket, failures, locked_until, updated_at)
                     VALUES (?, ?, datetime(\'now\', ?), datetime(\'now\'))
                     ON CONFLICT(bucket) DO UPDATE SET
                        failures = excluded.failures,
                        locked_until = excluded.locked_until,
                        updated_at = excluded.updated_at',
                    [$bucket, $failures, $lockExpr],
                );
            });
    }

    public function clear(string $bucket): PromiseInterface
    {
        return $this->db->query('DELETE FROM auth_rate_limits WHERE bucket = ?', [$bucket]);
    }

    private function isActiveLock(string $lockedUntil): bool
    {
        if ($lockedUntil === '') {
            return false;
        }

        $timestamp = strtotime($lockedUntil);

        return $timestamp !== false && $timestamp > time();
    }
}
