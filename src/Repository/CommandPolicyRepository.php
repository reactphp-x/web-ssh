<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;

final class CommandPolicyRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findOverlaysForContext(?int $hostId, ?int $hostGroupId): array
    {
        $rows = [];
        foreach ($this->listEnabledSync() as $row) {
            $scopeType = (string) ($row['scope_type'] ?? 'global');
            $scopeId = isset($row['scope_id']) ? (int) $row['scope_id'] : null;
            if ($scopeType === 'global') {
                $rows[] = $row;
                continue;
            }
            if ($scopeType === 'host' && $hostId !== null && $scopeId === $hostId) {
                $rows[] = $row;
                continue;
            }
            if ($scopeType === 'host_group' && $hostGroupId !== null && $scopeId === $hostGroupId) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 0)) <=> ((int) ($b['priority'] ?? 0)));

        return array_map([$this, 'decodeRules'], $rows);
    }

    /**
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function listAll(): PromiseInterface
    {
        return $this->db
            ->query('SELECT * FROM command_policies ORDER BY priority ASC, id ASC')
            ->then(static function ($result): array {
                $items = [];
                foreach ($result->resultRows ?? [] as $row) {
                    $items[] = self::mapRow($row);
                }

                return $items;
            });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function upsert(array $input): PromiseInterface
    {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $name = trim((string) ($input['name'] ?? ''));
        $scopeType = trim((string) ($input['scope_type'] ?? 'global'));
        $scopeId = isset($input['scope_id']) && $input['scope_id'] !== '' ? (int) $input['scope_id'] : null;
        $priority = (int) ($input['priority'] ?? 0);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $rulesJson = json_encode($input['rules'] ?? [], JSON_THROW_ON_ERROR);

        if ($id > 0) {
            return $this->db
                ->query(
                    'UPDATE command_policies
                     SET name = ?, scope_type = ?, scope_id = ?, priority = ?, enabled = ?, rules_json = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?',
                    [$name, $scopeType, $scopeId, $priority, $enabled, $rulesJson, $id],
                )
                ->then(fn (): PromiseInterface => $this->findById($id));
        }

        return $this->db
            ->query(
                'INSERT INTO command_policies (name, scope_type, scope_id, priority, enabled, rules_json)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $scopeType, $scopeId, $priority, $enabled, $rulesJson],
            )
            ->then(function ($result): PromiseInterface {
                $id = (int) ($result->lastInsertRowId ?? 0);

                return $this->findById($id);
            });
    }

    public function delete(int $id): PromiseInterface
    {
        return $this->db->query('DELETE FROM command_policies WHERE id = ?', [$id]);
    }

    /**
     * @return PromiseInterface<?array<string, mixed>>
     */
    public function findById(int $id): PromiseInterface
    {
        return $this->db
            ->query('SELECT * FROM command_policies WHERE id = ? LIMIT 1', [$id])
            ->then(function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return self::mapRow($row);
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listEnabledSync(): array
    {
        $result = \React\Async\await($this->db->query(
            'SELECT * FROM command_policies WHERE enabled = 1 ORDER BY priority ASC, id ASC',
        ));

        $items = [];
        foreach ($result->resultRows ?? [] as $row) {
            $items[] = self::mapRow($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeRules(array $row): array
    {
        $rules = $row['rules'] ?? [];
        if (!is_array($rules)) {
            return [];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $rules = json_decode((string) ($row['rules_json'] ?? '{}'), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'scope_type' => (string) ($row['scope_type'] ?? 'global'),
            'scope_id' => isset($row['scope_id']) ? (int) $row['scope_id'] : null,
            'priority' => (int) ($row['priority'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'rules' => is_array($rules) ? $rules : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
