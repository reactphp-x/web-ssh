<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use App\Security\SecretCipher;
use React\Promise\PromiseInterface;

final class TwoFactorRepository
{
    public function __construct(
        private readonly DatabaseClient $db,
        private readonly SecretCipher $cipher,
    ) {
    }

    /**
     * @return PromiseInterface<?array{username: string, label: string, secret: string, created_at: string}>
     */
    public function findByUsername(string $username): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT username, label, encrypted_secret, created_at FROM two_factor_auth WHERE username = ? LIMIT 1',
                [$username],
            )
            ->then(static function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return [
                    'username' => (string) $row['username'],
                    'label' => (string) $row['label'],
                    'secret' => (string) $row['encrypted_secret'],
                    'created_at' => (string) $row['created_at'],
                ];
            });
    }

    /**
     * @return PromiseInterface<?array{username: string, label: string, secret: string, created_at: string}>
     */
    public function findPendingByUsername(string $username): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT username, label, encrypted_secret, created_at FROM two_factor_pending WHERE username = ? LIMIT 1',
                [$username],
            )
            ->then(static function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return [
                    'username' => (string) $row['username'],
                    'label' => (string) $row['label'],
                    'secret' => (string) $row['encrypted_secret'],
                    'created_at' => (string) $row['created_at'],
                ];
            });
    }

    public function savePending(string $username, string $label, string $secret): PromiseInterface
    {
        return $this->db->query(
            'INSERT INTO two_factor_pending (username, label, encrypted_secret, created_at)
             VALUES (?, ?, ?, datetime(\'now\'))
             ON CONFLICT(username) DO UPDATE SET
                label = excluded.label,
                encrypted_secret = excluded.encrypted_secret,
                created_at = excluded.created_at',
            [$username, $label, $this->cipher->encrypt($secret)],
        );
    }

    public function confirm(string $username, string $label, string $secret): PromiseInterface
    {
        return $this->db
            ->query(
                'INSERT INTO two_factor_auth (username, label, encrypted_secret, created_at)
                 VALUES (?, ?, ?, datetime(\'now\'))
                 ON CONFLICT(username) DO UPDATE SET
                    label = excluded.label,
                    encrypted_secret = excluded.encrypted_secret,
                    created_at = excluded.created_at',
                [$username, $label, $this->cipher->encrypt($secret)],
            )
            ->then(fn () => $this->db->query('DELETE FROM two_factor_pending WHERE username = ?', [$username]));
    }

    public function deletePending(string $username): PromiseInterface
    {
        return $this->db->query('DELETE FROM two_factor_pending WHERE username = ?', [$username]);
    }

    public function decryptSecret(string $encryptedSecret): string
    {
        return $this->cipher->decrypt($encryptedSecret);
    }
}
