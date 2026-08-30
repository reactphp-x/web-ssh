<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;

final class TwoFactorSessionRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    public function create(string $username, string $token, int $ttlSeconds): PromiseInterface
    {
        return $this->db->query(
            'INSERT INTO two_factor_sessions (token, username, expires_at, created_at)
             VALUES (?, ?, datetime(\'now\', ?), datetime(\'now\'))
             ON CONFLICT(token) DO UPDATE SET
                username = excluded.username,
                expires_at = excluded.expires_at,
                created_at = excluded.created_at',
            [$token, $username, '+' . $ttlSeconds . ' seconds'],
        );
    }

    /**
     * @return PromiseInterface<?array{token: string, username: string, expires_at: string}>
     */
    public function findValid(string $token, string $username): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT token, username, expires_at FROM two_factor_sessions
                 WHERE token = ? AND username = ? AND expires_at > datetime(\'now\')
                 LIMIT 1',
                [$token, $username],
            )
            ->then(static function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return [
                    'token' => (string) $row['token'],
                    'username' => (string) $row['username'],
                    'expires_at' => (string) $row['expires_at'],
                ];
            });
    }

    public function deleteByToken(string $token): PromiseInterface
    {
        return $this->db->query('DELETE FROM two_factor_sessions WHERE token = ?', [$token]);
    }

    public function deleteByUsername(string $username): PromiseInterface
    {
        return $this->db->query('DELETE FROM two_factor_sessions WHERE username = ?', [$username]);
    }

    public function purgeExpired(): PromiseInterface
    {
        return $this->db->query('DELETE FROM two_factor_sessions WHERE expires_at <= datetime(\'now\')');
    }
}
