<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;

final class AuditLogRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    public function write(
        string $username,
        string $action,
        string $resource,
        ?int $resourceId,
        ?string $detail,
        string $ip,
    ): PromiseInterface {
        return $this->db
            ->query(
                'INSERT INTO audit_logs (username, action, resource, resource_id, detail, ip)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$username, $action, $resource, $resourceId, $detail, $ip],
            )
            ->then(static fn (): bool => true);
    }

    /**
     * @return PromiseInterface<array{items: list<array<string, mixed>>, total: int}>
     */
    public function paginate(
        int $page,
        int $perPage,
        ?string $username = null,
        ?string $action = null,
        ?string $resource = null,
    ): PromiseInterface {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($username !== null && $username !== '') {
            $where[] = 'username = ?';
            $params[] = $username;
        }
        if ($action !== null && $action !== '') {
            $where[] = 'action = ?';
            $params[] = $action;
        }
        if ($resource !== null && $resource !== '') {
            $where[] = 'resource = ?';
            $params[] = $resource;
        }

        $whereSql = implode(' AND ', $where);

        return $this->db
            ->query("SELECT COUNT(*) AS total FROM audit_logs WHERE {$whereSql}", $params)
            ->then(function ($countResult) use ($whereSql, $params, $perPage, $offset): PromiseInterface {
                $total = (int) ($countResult->resultRows[0]['total'] ?? 0);

                return $this->db
                    ->query(
                        "SELECT id, username, action, resource, resource_id, detail, ip, created_at
                         FROM audit_logs
                         WHERE {$whereSql}
                         ORDER BY id DESC
                         LIMIT {$perPage} OFFSET {$offset}",
                        $params,
                    )
                    ->then(static function ($result) use ($total): array {
                        $items = array_map(static fn (array $row): array => [
                            'id' => (int) $row['id'],
                            'username' => (string) $row['username'],
                            'action' => (string) $row['action'],
                            'resource' => (string) $row['resource'],
                            'resource_id' => isset($row['resource_id']) ? (int) $row['resource_id'] : null,
                            'detail' => $row['detail'] ?? null,
                            'ip' => (string) ($row['ip'] ?? ''),
                            'created_at' => (string) $row['created_at'],
                        ], $result->resultRows ?? []);

                        return ['items' => $items, 'total' => $total];
                    });
            });
    }
}
