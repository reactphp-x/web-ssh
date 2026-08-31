<?php

declare(strict_types=1);

namespace App\Repository;

use App\Chat\AiSettingsStore;
use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class AiSettingsRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    public function listAll(): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT id, name, config_json, is_selected, updated_at, updated_by
                 FROM ai_profiles
                 ORDER BY is_selected DESC, id ASC',
            )
            ->then(static function ($result): array {
                $items = [];
                foreach ($result->resultRows ?? [] as $row) {
                    $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
                    $config = is_array($config) ? $config : [];
                    $items[] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'provider' => (string) ($config['provider'] ?? 'openai'),
                        'model' => (string) ($config['model'] ?? ''),
                        'enabled' => filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOL),
                        'is_selected' => (int) ($row['is_selected'] ?? 0) === 1,
                        'updated_at' => (string) ($row['updated_at'] ?? ''),
                        'updated_by' => (string) ($row['updated_by'] ?? ''),
                    ];
                }

                return $items;
            });
    }

    public function findById(int $id): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT id, name, config_json, encrypted_secrets, is_selected, updated_at, updated_by
                 FROM ai_profiles
                 WHERE id = ?
                 LIMIT 1',
                [$id],
            )
            ->then(static function ($result): ?array {
                return $result->resultRows[0] ?? null;
            });
    }

    public function findSelected(): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT id, name, config_json, encrypted_secrets, is_selected, updated_at, updated_by
                 FROM ai_profiles
                 WHERE is_selected = 1
                 ORDER BY id ASC
                 LIMIT 1',
            )
            ->then(static function ($result): ?array {
                return $result->resultRows[0] ?? null;
            });
    }

    public function create(string $name, AiSettingsStore $store, string $updatedBy, bool $select = false): PromiseInterface
    {
        return $this->db
            ->query(
                'INSERT INTO ai_profiles (name, config_json, encrypted_secrets, is_selected, updated_at, updated_by)
                 VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, ?)',
                [
                    $name,
                    $store->encodeConfig(),
                    $store->encodeSecrets(),
                    $updatedBy,
                ],
            )
            ->then(function ($result) use ($select, $updatedBy): PromiseInterface {
                $id = (int) $result->insertId;
                if (!$select) {
                    return resolve($id);
                }

                return $this->select($id, $updatedBy)->then(static fn (): int => $id);
            });
    }

    public function update(int $id, string $name, AiSettingsStore $store, string $updatedBy): PromiseInterface
    {
        return $this->db
            ->query(
                'UPDATE ai_profiles
                 SET name = ?, config_json = ?, encrypted_secrets = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?
                 WHERE id = ?',
                [
                    $name,
                    $store->encodeConfig(),
                    $store->encodeSecrets(),
                    $updatedBy,
                    $id,
                ],
            )
            ->then(static fn (): null => null);
    }

    public function delete(int $id): PromiseInterface
    {
        return $this->db
            ->query('DELETE FROM ai_profiles WHERE id = ?', [$id])
            ->then(static fn (): null => null);
    }

    public function select(int $id, string $updatedBy): PromiseInterface
    {
        return $this->db
            ->query('UPDATE ai_profiles SET is_selected = 0')
            ->then(fn (): PromiseInterface => $this->db->query(
                'UPDATE ai_profiles SET is_selected = 1, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?',
                [$updatedBy, $id],
            ))
            ->then(static fn (): null => null);
    }

    public function deselectAll(): PromiseInterface
    {
        return $this->db
            ->query('UPDATE ai_profiles SET is_selected = 0')
            ->then(static fn (): null => null);
    }

    public function count(): PromiseInterface
    {
        return $this->db
            ->query('SELECT COUNT(*) AS total FROM ai_profiles')
            ->then(static fn ($result): int => (int) ($result->resultRows[0]['total'] ?? 0));
    }
}
