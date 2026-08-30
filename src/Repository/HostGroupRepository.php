<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;

final class HostGroupRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    /**
     * @return PromiseInterface<list<array{id: int, name: string, parent_id: ?int}>>
     */
    public function listAll(): PromiseInterface
    {
        return $this->db
            ->query('SELECT id, name, parent_id FROM host_groups ORDER BY id ASC')
            ->then(static function ($result): array {
                return array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
                ], $result->resultRows ?? []);
            });
    }
}
