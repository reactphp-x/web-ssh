<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;

final class AiSessionRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    public function create(string $username, string $title = ''): PromiseInterface
    {
        return $this->db
            ->query(
                'INSERT INTO ai_sessions (username, title, status, created_at, updated_at)
                 VALUES (?, ?, \'active\', datetime(\'now\'), datetime(\'now\'))',
                [$username, $title],
            )
            ->then(fn ($result): int => (int) $result->insertId);
    }

    public function findById(int $id): PromiseInterface
    {
        return $this->db
            ->query('SELECT * FROM ai_sessions WHERE id = ? LIMIT 1', [$id])
            ->then(static function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return self::mapSession($row);
            });
    }

    /**
     * @return PromiseInterface<array{items: list<array<string, mixed>>, total: int}>
     */
    public function paginate(int $page, int $perPage, ?string $username = null): PromiseInterface
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];
        if ($username !== null && $username !== '') {
            $where[] = 'a.username = ?';
            $params[] = $username;
        }
        $whereSql = implode(' AND ', $where);

        return $this->db
            ->query("SELECT COUNT(*) AS total FROM ai_sessions a WHERE {$whereSql}", $params)
            ->then(function ($countResult) use ($whereSql, $params, $perPage, $offset): PromiseInterface {
                $total = (int) ($countResult->resultRows[0]['total'] ?? 0);

                return $this->db
                    ->query(
                        "SELECT a.*,
                                (SELECT COUNT(*) FROM ai_session_segments s WHERE s.ai_session_id = a.id) AS segment_count,
                                (SELECT COUNT(DISTINCT s.host_id) FROM ai_session_segments s WHERE s.ai_session_id = a.id) AS host_count
                         FROM ai_sessions a
                         WHERE {$whereSql}
                         ORDER BY a.id DESC
                         LIMIT {$perPage} OFFSET {$offset}",
                        $params,
                    )
                    ->then(static function ($result) use ($total): array {
                        $items = array_map(static function (array $row): array {
                            $session = self::mapSession($row);

                            return array_merge($session, [
                                'segment_count' => (int) ($row['segment_count'] ?? 0),
                                'host_count' => (int) ($row['host_count'] ?? 0),
                            ]);
                        }, $result->resultRows ?? []);

                        return ['items' => $items, 'total' => $total];
                    });
            });
    }

    public function touch(int $id, ?string $title = null): PromiseInterface
    {
        if ($title !== null && trim($title) !== '') {
            return $this->db
                ->query(
                    'UPDATE ai_sessions SET title = ?, updated_at = datetime(\'now\') WHERE id = ?',
                    [trim($title), $id],
                )
                ->then(static fn (): bool => true);
        }

        return $this->db
            ->query('UPDATE ai_sessions SET updated_at = datetime(\'now\') WHERE id = ?', [$id])
            ->then(static fn (): bool => true);
    }

    public function setActiveSegment(int $id, ?int $segmentId): PromiseInterface
    {
        return $this->db
            ->query(
                'UPDATE ai_sessions SET active_segment_id = ?, updated_at = datetime(\'now\') WHERE id = ?',
                [$segmentId, $id],
            )
            ->then(static fn (): bool => true);
    }

    public function finish(int $id, string $status = 'completed'): PromiseInterface
    {
        return $this->db
            ->query(
                'UPDATE ai_sessions SET status = ?, ended_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = ?',
                [$status, $id],
            )
            ->then(static fn (): bool => true);
    }

    public function updateLiveKey(int $segmentId, string $liveKey): PromiseInterface
    {
        return $this->db
            ->query('UPDATE ai_session_segments SET live_key = ? WHERE id = ?', [$liveKey, $segmentId])
            ->then(static fn (): bool => true);
    }

    public function createSegment(
        int $aiSessionId,
        int $hostId,
        string $liveKey,
        int $orderIndex,
        ?int $sessionId = null,
    ): PromiseInterface {
        return $this->db
            ->query(
                'INSERT INTO ai_session_segments (ai_session_id, host_id, session_id, live_key, order_index, started_at)
                 VALUES (?, ?, ?, ?, ?, datetime(\'now\'))',
                [$aiSessionId, $hostId, $sessionId, $liveKey, $orderIndex],
            )
            ->then(fn ($result): int => (int) $result->insertId);
    }

    public function countSegments(int $aiSessionId): PromiseInterface
    {
        return $this->db
            ->query('SELECT COUNT(*) AS total FROM ai_session_segments WHERE ai_session_id = ?', [$aiSessionId])
            ->then(static fn ($result): int => (int) ($result->resultRows[0]['total'] ?? 0));
    }

    public function finishSegment(int $segmentId): PromiseInterface
    {
        return $this->db
            ->query(
                'UPDATE ai_session_segments SET ended_at = datetime(\'now\') WHERE id = ?',
                [$segmentId],
            )
            ->then(static fn (): bool => true);
    }

    /**
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function listSegments(int $aiSessionId): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT s.*, h.name AS host_name, h.address AS host_address, h.port AS host_port,
                        sess.recording_url, sess.status AS session_status, sess.duration, sess.start_time
                 FROM ai_session_segments s
                 INNER JOIN hosts h ON h.id = s.host_id
                 LEFT JOIN sessions sess ON sess.id = s.session_id
                 WHERE s.ai_session_id = ?
                 ORDER BY s.order_index ASC, s.id ASC',
                [$aiSessionId],
            )
            ->then(static function ($result): array {
                return array_map(static function (array $row): array {
                    return [
                        'id' => (int) $row['id'],
                        'ai_session_id' => (int) $row['ai_session_id'],
                        'host_id' => (int) $row['host_id'],
                        'host_name' => (string) $row['host_name'],
                        'host_address' => (string) $row['host_address'],
                        'host_port' => (int) $row['host_port'],
                        'session_id' => isset($row['session_id']) ? (int) $row['session_id'] : null,
                        'live_key' => (string) $row['live_key'],
                        'order_index' => (int) $row['order_index'],
                        'started_at' => (string) $row['started_at'],
                        'ended_at' => $row['ended_at'] ?? null,
                        'recording_url' => $row['recording_url'] ?? null,
                        'session_status' => $row['session_status'] ?? null,
                        'duration' => isset($row['duration']) ? (int) $row['duration'] : null,
                        'start_time' => $row['start_time'] ?? null,
                    ];
                }, $result->resultRows ?? []);
            });
    }

    public function findSegment(int $segmentId): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT s.*, h.name AS host_name, h.address AS host_address
                 FROM ai_session_segments s
                 INNER JOIN hosts h ON h.id = s.host_id
                 WHERE s.id = ?
                 LIMIT 1',
                [$segmentId],
            )
            ->then(static function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return [
                    'id' => (int) $row['id'],
                    'ai_session_id' => (int) $row['ai_session_id'],
                    'host_id' => (int) $row['host_id'],
                    'host_name' => (string) $row['host_name'],
                    'host_address' => (string) $row['host_address'],
                    'session_id' => isset($row['session_id']) ? (int) $row['session_id'] : null,
                    'live_key' => (string) $row['live_key'],
                    'order_index' => (int) $row['order_index'],
                ];
            });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapSession(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'username' => (string) $row['username'],
            'status' => (string) $row['status'],
            'active_segment_id' => isset($row['active_segment_id']) ? (int) $row['active_segment_id'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'ended_at' => $row['ended_at'] ?? null,
        ];
    }
}
