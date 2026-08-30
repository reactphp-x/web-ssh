<?php

declare(strict_types=1);

namespace App\Ssh;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

final class SshLiveRegistry
{
    /** @var array<string, array{
     *     conn_id: string,
     *     session_id: ?int,
     *     platform_user: string,
     *     host_id: int,
     *     host_name: string,
     *     host_address: string,
     *     host_port: int,
     *     ssh_user: string,
     *     status: string,
     *     started_at_unix: int,
     *     cols: int,
     *     rows: int,
     *     hub: SshOutputHub
     * }> */
    private array $live = [];

    /**
     * @param array<string, mixed> $host
     */
    public function registerPending(string $connId, string $platformUser, int $hostId, array $host): void
    {
        $this->live[$connId] = [
            'conn_id' => $connId,
            'session_id' => null,
            'platform_user' => $platformUser,
            'host_id' => $hostId,
            'host_name' => (string) ($host['name'] ?? ''),
            'host_address' => (string) ($host['address'] ?? ''),
            'host_port' => (int) ($host['port'] ?? 22),
            'ssh_user' => (string) ($host['username'] ?? ''),
            'status' => 'connecting',
            'started_at_unix' => time(),
            'cols' => 80,
            'rows' => 24,
            'hub' => new SshOutputHub(),
        ];

        $this->live[$connId]['hub']->write('start', [
            'conn_id' => $connId,
            'host_name' => $this->live[$connId]['host_name'],
            'host_address' => $this->live[$connId]['host_address'],
            'ssh_user' => $this->live[$connId]['ssh_user'],
            'platform_user' => $platformUser,
        ]);
    }

    public function markSessionStarted(string $connId, int $sessionId): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        $this->live[$connId]['session_id'] = $sessionId;
    }

    public function markConnected(string $connId): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        $this->live[$connId]['status'] = 'connected';
        $entry = $this->live[$connId];
        $this->live[$connId]['hub']->write('connected', [
            'conn_id' => $connId,
            'session_id' => $entry['session_id'],
            'host_name' => $entry['host_name'],
            'host_address' => $entry['host_address'],
            'ssh_user' => $entry['ssh_user'],
        ]);
    }

    public function writeResize(string $connId, int $cols, int $rows): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        $cols = max(1, $cols);
        $rows = max(1, $rows);
        $this->live[$connId]['cols'] = $cols;
        $this->live[$connId]['rows'] = $rows;
        $this->live[$connId]['hub']->write('resize', [
            'cols' => $cols,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array{cols: int, rows: int}
     */
    public function getTerminalSize(string $connId): array
    {
        $entry = $this->live[$connId] ?? null;
        if ($entry === null) {
            return ['cols' => 80, 'rows' => 24];
        }

        return [
            'cols' => max(1, (int) $entry['cols']),
            'rows' => max(1, (int) $entry['rows']),
        ];
    }

    public function writeOutput(string $connId, string $chunk): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        $this->live[$connId]['hub']->write('output', [
            'chunk' => base64_encode($chunk),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function writeError(string $connId, array $payload): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        if ($this->live[$connId]['hub']->isClosed()) {
            return;
        }

        $this->live[$connId]['status'] = 'failed';
        $this->live[$connId]['hub']->write('error', $payload);
        $this->live[$connId]['hub']->close();
    }

    public function finish(string $connId, string $kind, string $message = ''): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        if ($this->live[$connId]['hub']->isClosed()) {
            return;
        }

        $this->live[$connId]['status'] = $kind === 'error' ? 'failed' : 'finished';
        $this->live[$connId]['hub']->write($kind, [
            'message' => $message !== '' ? $message : 'SSH 会话已结束',
        ]);
        $this->live[$connId]['hub']->close();
    }

    public function remove(string $connId): void
    {
        if (!isset($this->live[$connId])) {
            return;
        }

        if (!$this->live[$connId]['hub']->isClosed()) {
            $this->live[$connId]['hub']->close();
        }
        unset($this->live[$connId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRunning(): array
    {
        $items = [];
        foreach ($this->live as $entry) {
            if ($entry['hub']->isClosed()) {
                continue;
            }
            $items[] = [
                'id' => $entry['conn_id'],
                'session_id' => $entry['session_id'],
                'title' => $entry['host_name'] !== '' ? $entry['host_name'] : $entry['host_address'],
                'host_name' => $entry['host_name'],
                'host_address' => $entry['host_address'],
                'host_port' => $entry['host_port'],
                'ssh_user' => $entry['ssh_user'],
                'platform_user' => $entry['platform_user'],
                'status' => $entry['status'],
                'started_at_unix' => $entry['started_at_unix'],
                'cols' => $entry['cols'],
                'rows' => $entry['rows'],
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ($b['started_at_unix'] ?? 0) <=> ($a['started_at_unix'] ?? 0),
        );

        return $items;
    }

    public function watch(string $connId): ?ResponseInterface
    {
        $entry = $this->live[$connId] ?? null;
        if ($entry === null) {
            return null;
        }

        $stream = new ThroughStream();
        $entry['hub']->attach($stream, true, 'joined running SSH session');

        return \App\Http\Sse::response($stream);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFinished(): array
    {
        $items = [];
        foreach ($this->live as $entry) {
            if (!$entry['hub']->isClosed()) {
                continue;
            }
            $items[] = [
                'id' => $entry['conn_id'],
                'session_id' => $entry['session_id'],
                'title' => $entry['host_name'] !== '' ? $entry['host_name'] : $entry['host_address'],
                'host_name' => $entry['host_name'],
                'host_address' => $entry['host_address'],
                'host_port' => $entry['host_port'],
                'ssh_user' => $entry['ssh_user'],
                'platform_user' => $entry['platform_user'],
                'status' => $entry['status'],
                'started_at_unix' => $entry['started_at_unix'],
                'cols' => $entry['cols'],
                'rows' => $entry['rows'],
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ($b['started_at_unix'] ?? 0) <=> ($a['started_at_unix'] ?? 0),
        );

        return $items;
    }
}
