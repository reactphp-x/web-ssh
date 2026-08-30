<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use App\Security\SecretCipher;
use React\Promise\PromiseInterface;

final class HostRepository
{
    public function __construct(
        private readonly DatabaseClient $db,
        private readonly SecretCipher $cipher,
    ) {
    }

    /**
     * @return PromiseInterface<array{items: list<array<string, mixed>>, total: int}>
     */
    public function paginate(
        int $page,
        int $perPage,
        ?string $keyword = null,
        ?int $groupId = null,
        ?string $tag = null,
    ): PromiseInterface {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($keyword !== null && $keyword !== '') {
            $where[] = '(h.name LIKE ? OR h.address LIKE ? OR h.username LIKE ? OR h.tags LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }

        if ($groupId !== null && $groupId > 0) {
            $where[] = 'h.group_id = ?';
            $params[] = $groupId;
        }

        if ($tag !== null && $tag !== '') {
            $where[] = '(h.tags = ? OR h.tags LIKE ? OR h.tags LIKE ? OR h.tags LIKE ?)';
            $params[] = $tag;
            $params[] = $tag . ',%';
            $params[] = '%,' . $tag . ',%';
            $params[] = '%,' . $tag;
        }

        $whereSql = implode(' AND ', $where);

        return $this->db
            ->query(
                "SELECT COUNT(*) AS total FROM hosts h WHERE {$whereSql}",
                $params,
            )
            ->then(function ($countResult) use ($whereSql, $params, $perPage, $offset): PromiseInterface {
                $total = (int) ($countResult->resultRows[0]['total'] ?? 0);

                return $this->db
                    ->query(
                        "SELECT h.id, h.name, h.address, h.port, h.username, h.auth_type, h.group_id,
                                g.name AS group_name, h.tags, h.remark, h.created_by,
                                h.last_connected_at, h.created_at, h.updated_at,
                                h.jump_host_id, j.name AS jump_host_name
                         FROM hosts h
                         LEFT JOIN host_groups g ON g.id = h.group_id
                         LEFT JOIN hosts j ON j.id = h.jump_host_id
                         WHERE {$whereSql}
                         ORDER BY h.id DESC
                         LIMIT {$perPage} OFFSET {$offset}",
                        $params,
                    )
                    ->then(static function ($result) use ($total): array {
                        $items = array_map(
                            static fn (array $row): array => self::mapPublicRow($row),
                            $result->resultRows ?? [],
                        );

                        return ['items' => $items, 'total' => $total];
                    });
            });
    }

    public function findById(int $id): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT h.*, g.name AS group_name, j.name AS jump_host_name
                 FROM hosts h
                 LEFT JOIN host_groups g ON g.id = h.group_id
                 LEFT JOIN hosts j ON j.id = h.jump_host_id
                 WHERE h.id = ?
                 LIMIT 1',
                [$id],
            )
            ->then(function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return $this->mapInternalRow($row);
            });
    }

    public function findPublicById(int $id): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT h.id, h.name, h.address, h.port, h.username, h.auth_type, h.private_key_source,
                        h.encrypted_secret, h.group_id, g.name AS group_name, h.tags, h.remark, h.created_by,
                        h.last_connected_at, h.created_at, h.updated_at,
                        h.jump_host_id, j.name AS jump_host_name
                 FROM hosts h
                 LEFT JOIN host_groups g ON g.id = h.group_id
                 LEFT JOIN hosts j ON j.id = h.jump_host_id
                 WHERE h.id = ?
                 LIMIT 1',
                [$id],
            )
            ->then(fn ($result): ?array => $this->mapDetailRow($result->resultRows[0] ?? null));
    }

    public function nameExists(string $name, ?int $excludeId = null): PromiseInterface
    {
        $sql = 'SELECT id FROM hosts WHERE name = ?';
        $params = [$name];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        return $this->db
            ->query($sql . ' LIMIT 1', $params)
            ->then(static fn ($result): bool => isset($result->resultRows[0]));
    }

    /**
     * @return PromiseInterface<list<array{id: int, name: string, address: string, port: int, username: string}>>
     */
    public function listOptions(): PromiseInterface
    {
        return $this->db
            ->query('SELECT id, name, address, port, username FROM hosts ORDER BY name ASC')
            ->then(static function ($result): array {
                return array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'address' => (string) $row['address'],
                    'port' => (int) $row['port'],
                    'username' => (string) $row['username'],
                ], $result->resultRows ?? []);
            });
    }

    /**
     * @return PromiseInterface<array<int, int|null>>
     */
    public function listJumpMap(): PromiseInterface
    {
        return $this->db
            ->query('SELECT id, jump_host_id FROM hosts')
            ->then(static function ($result): array {
                $map = [];
                foreach ($result->resultRows ?? [] as $row) {
                    $id = (int) $row['id'];
                    $jumpId = isset($row['jump_host_id']) && $row['jump_host_id'] !== '' && $row['jump_host_id'] !== null
                        ? (int) $row['jump_host_id']
                        : null;
                    $map[$id] = $jumpId !== null && $jumpId > 0 ? $jumpId : null;
                }

                return $map;
            });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): PromiseInterface
    {
        return $this->db
            ->query(
                'INSERT INTO hosts
                    (name, address, port, username, auth_type, private_key_source, encrypted_secret, encrypted_passphrase,
                     jump_host_id, group_id, tags, remark, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['name'],
                    $data['address'],
                    $data['port'],
                    $data['username'],
                    $data['auth_type'],
                    $data['private_key_source'] ?? 'path',
                    $data['encrypted_secret'],
                    $data['encrypted_passphrase'],
                    $data['jump_host_id'],
                    $data['group_id'],
                    $data['tags'],
                    $data['remark'],
                    $data['created_by'],
                ],
            )
            ->then(fn ($result): int => (int) $result->insertId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): PromiseInterface
    {
        return $this->db
            ->query(
                'UPDATE hosts SET
                    name = ?, port = ?, username = ?, auth_type = ?, private_key_source = ?,
                    encrypted_secret = ?, encrypted_passphrase = ?,
                    jump_host_id = ?, group_id = ?, tags = ?, remark = ?, updated_at = datetime(\'now\')
                 WHERE id = ?',
                [
                    $data['name'],
                    $data['port'],
                    $data['username'],
                    $data['auth_type'],
                    $data['private_key_source'] ?? 'path',
                    $data['encrypted_secret'],
                    $data['encrypted_passphrase'],
                    $data['jump_host_id'],
                    $data['group_id'],
                    $data['tags'],
                    $data['remark'],
                    $id,
                ],
            )
            ->then(static fn (): bool => true);
    }

    public function delete(int $id): PromiseInterface
    {
        return $this->db
            ->query('UPDATE hosts SET jump_host_id = NULL WHERE jump_host_id = ?', [$id])
            ->then(fn () => $this->db->query('DELETE FROM hosts WHERE id = ?', [$id]))
            ->then(static fn ($result): bool => $result->affectedRows > 0);
    }

    public function touchLastConnected(int $id): PromiseInterface
    {
        return $this->db
            ->query('UPDATE hosts SET last_connected_at = datetime(\'now\') WHERE id = ?', [$id])
            ->then(static fn (): bool => true);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapInternalRow(array $row): array
    {
        $authType = (string) $row['auth_type'];
        $keySource = (string) ($row['private_key_source'] ?? 'pem');
        $secret = $this->cipher->decrypt((string) $row['encrypted_secret']);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'address' => (string) $row['address'],
            'port' => (int) $row['port'],
            'username' => (string) $row['username'],
            'auth_type' => $authType,
            'private_key_source' => $keySource,
            'password' => $authType === 'password' ? $secret : null,
            'private_key' => $authType === 'private_key' && $keySource === 'pem' ? $secret : null,
            'private_key_path' => $authType === 'private_key' && $keySource === 'path' ? $secret : null,
            'passphrase' => ($row['encrypted_passphrase'] ?? '') !== ''
                ? $this->cipher->decrypt((string) $row['encrypted_passphrase'])
                : null,
            'group_id' => isset($row['group_id']) ? (int) $row['group_id'] : null,
            'group_name' => (string) ($row['group_name'] ?? ''),
            'jump_host_id' => self::nullablePositiveInt($row['jump_host_id'] ?? null),
            'jump_host_name' => (string) ($row['jump_host_name'] ?? ''),
            'tags' => (string) ($row['tags'] ?? ''),
            'remark' => (string) ($row['remark'] ?? ''),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'last_connected_at' => $row['last_connected_at'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>|null
     */
    private function mapDetailRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $authType = (string) $row['auth_type'];
        $keySource = (string) ($row['private_key_source'] ?? 'pem');
        $privateKeyPath = null;

        if ($authType === 'private_key' && $keySource === 'path') {
            $privateKeyPath = $this->cipher->decrypt((string) $row['encrypted_secret']);
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'address' => (string) $row['address'],
            'port' => (int) $row['port'],
            'username' => (string) $row['username'],
            'auth_type' => $authType,
            'private_key_source' => $keySource,
            'private_key_path' => $privateKeyPath,
            'group_id' => isset($row['group_id']) ? (int) $row['group_id'] : null,
            'group_name' => (string) ($row['group_name'] ?? ''),
            'jump_host_id' => self::nullablePositiveInt($row['jump_host_id'] ?? null),
            'jump_host_name' => (string) ($row['jump_host_name'] ?? ''),
            'tags' => (string) ($row['tags'] ?? ''),
            'remark' => (string) ($row['remark'] ?? ''),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'last_connected_at' => $row['last_connected_at'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function mapPublicRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'address' => (string) $row['address'],
            'port' => (int) $row['port'],
            'username' => (string) $row['username'],
            'auth_type' => (string) $row['auth_type'],
            'private_key_source' => (string) ($row['private_key_source'] ?? 'path'),
            'group_id' => isset($row['group_id']) ? (int) $row['group_id'] : null,
            'group_name' => (string) ($row['group_name'] ?? ''),
            'jump_host_id' => self::nullablePositiveInt($row['jump_host_id'] ?? null),
            'jump_host_name' => (string) ($row['jump_host_name'] ?? ''),
            'tags' => (string) ($row['tags'] ?? ''),
            'remark' => (string) ($row['remark'] ?? ''),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'last_connected_at' => $row['last_connected_at'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
