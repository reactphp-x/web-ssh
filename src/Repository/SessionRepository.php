<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use React\Promise\PromiseInterface;

final class SessionRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    public function create(
        string $username,
        int $hostId,
        string $status,
        ?string $errorMessage = null,
        string $sessionType = 'terminal',
        ?int $aiSessionId = null,
    ): PromiseInterface {
        return $this->db
            ->query(
                'INSERT INTO sessions (username, host_id, session_type, ai_session_id, status, error_message, start_time)
                 VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))',
                [$username, $hostId, $sessionType, $aiSessionId, $status, $errorMessage],
            )
            ->then(fn ($result): int => (int) $result->insertId);
    }

    public function markConnected(int $sessionId): PromiseInterface
    {
        return $this->db
            ->query('UPDATE sessions SET status = ? WHERE id = ?', ['success', $sessionId])
            ->then(static fn (): bool => true);
    }

    public function finish(int $sessionId, string $status, ?string $errorMessage = null): PromiseInterface
    {
        return $this->db
            ->query(
                'UPDATE sessions SET
                    status = ?,
                    error_message = COALESCE(?, error_message),
                    end_time = datetime(\'now\'),
                    duration = CAST((strftime(\'%s\', \'now\') - strftime(\'%s\', start_time)) AS INTEGER)
                 WHERE id = ?',
                [$status, $errorMessage, $sessionId],
            )
            ->then(static fn (): bool => true);
    }

    public function setRecordingUrl(int $sessionId, string $recordingUrl): PromiseInterface
    {
        return $this->db
            ->query('UPDATE sessions SET recording_url = ? WHERE id = ?', [$recordingUrl, $sessionId])
            ->then(static fn (): bool => true);
    }

    /**
     * @return PromiseInterface<array{items: list<array<string, mixed>>, total: int}>
     */
    public function paginate(
        int $page,
        int $perPage,
        ?string $username = null,
        ?int $hostId = null,
        ?string $from = null,
        ?string $to = null,
    ): PromiseInterface {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($username !== null && $username !== '') {
            $where[] = 's.username = ?';
            $params[] = $username;
        }
        if ($hostId !== null && $hostId > 0) {
            $where[] = 's.host_id = ?';
            $params[] = $hostId;
        }
        if ($from !== null && $from !== '') {
            $where[] = 's.start_time >= ?';
            $params[] = $from;
        }
        if ($to !== null && $to !== '') {
            $where[] = 's.start_time <= ?';
            $params[] = $to;
        }

        $whereSql = implode(' AND ', $where);

        return $this->db
            ->query("SELECT COUNT(*) AS total FROM sessions s WHERE {$whereSql}", $params)
            ->then(function ($countResult) use ($whereSql, $params, $perPage, $offset): PromiseInterface {
                $total = (int) ($countResult->resultRows[0]['total'] ?? 0);

                return $this->db
                    ->query(
                        "SELECT s.*, h.name AS host_name, h.address AS host_address
                         FROM sessions s
                         INNER JOIN hosts h ON h.id = s.host_id
                         WHERE {$whereSql}
                         ORDER BY s.id DESC
                         LIMIT {$perPage} OFFSET {$offset}",
                        $params,
                    )
                    ->then(static function ($result) use ($total): array {
                        $items = array_map(static function (array $row): array {
                            return [
                                'id' => (int) $row['id'],
                                'username' => (string) $row['username'],
                                'host_id' => (int) $row['host_id'],
                                'session_type' => (string) ($row['session_type'] ?? 'terminal'),
                                'ai_session_id' => isset($row['ai_session_id']) ? (int) $row['ai_session_id'] : null,
                                'host_name' => (string) $row['host_name'],
                                'host_address' => (string) $row['host_address'],
                                'status' => (string) $row['status'],
                                'error_message' => $row['error_message'] ?? null,
                                'start_time' => (string) $row['start_time'],
                                'end_time' => $row['end_time'] ?? null,
                                'duration' => isset($row['duration']) ? (int) $row['duration'] : null,
                                'recording_url' => $row['recording_url'] ?? null,
                            ];
                        }, $result->resultRows ?? []);

                        return ['items' => $items, 'total' => $total];
                    });
            });
    }

    public function findById(int $id): PromiseInterface
    {
        return $this->db
            ->query(
                'SELECT s.*, h.name AS host_name, h.address AS host_address, h.port AS host_port
                 FROM sessions s
                 INNER JOIN hosts h ON h.id = s.host_id
                 WHERE s.id = ?
                 LIMIT 1',
                [$id],
            )
            ->then(static function ($result): ?array {
                $row = $result->resultRows[0] ?? null;
                if ($row === null) {
                    return null;
                }

                return [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    'host_id' => (int) $row['host_id'],
                    'session_type' => (string) ($row['session_type'] ?? 'terminal'),
                    'ai_session_id' => isset($row['ai_session_id']) ? (int) $row['ai_session_id'] : null,
                    'host_name' => (string) $row['host_name'],
                    'host_address' => (string) $row['host_address'],
                    'host_port' => (int) $row['host_port'],
                    'status' => (string) $row['status'],
                    'error_message' => $row['error_message'] ?? null,
                    'start_time' => (string) $row['start_time'],
                    'end_time' => $row['end_time'] ?? null,
                    'duration' => isset($row['duration']) ? (int) $row['duration'] : null,
                    'recording_url' => $row['recording_url'] ?? null,
                ];
            });
    }
}
