<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Http\Sse;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
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

    /** @var array<int, list<ThroughStream>> */
    private array $aiSessionIdleWatchers = [];

    /**
     * @param array<string, mixed> $host
     */
    public function registerPending(string $connId, string $platformUser, int $hostId, array $host): void
    {
        $this->registerEntry($connId, $platformUser, $hostId, $host, null);
    }

    /**
     * @param array<string, mixed> $host
     */
    public function registerAiSegment(
        string $liveKey,
        string $platformUser,
        int $hostId,
        array $host,
        int $sessionId,
        int $aiSessionId,
        int $segmentId,
    ): void {
        $this->registerEntry($liveKey, $platformUser, $hostId, $host, $sessionId, $aiSessionId, $segmentId);
    }

    /**
     * @param array<string, mixed> $host
     */
    private function registerEntry(
        string $connId,
        string $platformUser,
        int $hostId,
        array $host,
        ?int $sessionId,
        ?int $aiSessionId = null,
        ?int $segmentId = null,
    ): void {
        $this->live[$connId] = [
            'conn_id' => $connId,
            'session_id' => $sessionId,
            'ai_session_id' => $aiSessionId,
            'segment_id' => $segmentId,
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

        $payload = [
            'conn_id' => $connId,
            'host_name' => $this->live[$connId]['host_name'],
            'host_address' => $this->live[$connId]['host_address'],
            'ssh_user' => $this->live[$connId]['ssh_user'],
            'platform_user' => $platformUser,
        ];
        if ($aiSessionId !== null) {
            $payload['ai_session_id'] = $aiSessionId;
            $payload['segment_id'] = $segmentId;
            $this->promoteAiSessionIdleWatchers($aiSessionId, $connId);
        }
        $this->live[$connId]['hub']->write('start', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function writeAiSegmentSwitch(string $liveKey, array $payload): void
    {
        if (!isset($this->live[$liveKey])) {
            return;
        }

        $this->live[$liveKey]['hub']->write('segment_switch', $payload);
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

        return Sse::response($stream);
    }

    public function watchAiSession(
        int $aiSessionId,
        ?string $activeLiveKey,
        string $transcript = '',
        ?array $activeSegment = null,
    ): ResponseInterface {
        $stream = new ThroughStream();

        Loop::futureTick(function () use ($stream, $aiSessionId, $activeLiveKey, $transcript, $activeSegment): void {
            if (!$stream->isWritable()) {
                return;
            }

            if ($transcript !== '') {
                Sse::write($stream, 'replay', [
                    'chunk' => base64_encode($transcript),
                    'host_name' => $activeSegment['host_name'] ?? '',
                    'host_address' => $activeSegment['host_address'] ?? '',
                    'host_id' => $activeSegment['host_id'] ?? null,
                ]);
            }

            if ($activeLiveKey !== null && isset($this->live[$activeLiveKey]) && !$this->live[$activeLiveKey]['hub']->isClosed()) {
                $entry = $this->live[$activeLiveKey];
                Sse::write($stream, 'connected', [
                    'host_id' => $activeSegment['host_id'] ?? null,
                    'host_name' => $activeSegment['host_name'] ?? $entry['host_name'],
                    'host_address' => $activeSegment['host_address'] ?? $entry['host_address'],
                ]);
                $entry['hub']->attach($stream, false);

                return;
            }

            if ($activeSegment !== null) {
                Sse::write($stream, 'segment_switch', $activeSegment);
            }

            Sse::write($stream, 'status', [
                'state' => 'idle',
                'message' => $transcript !== ''
                    ? '已恢复历史输出，等待下一次命令…'
                    : '等待命令执行，AI 审核通过后将在此显示输出。',
            ]);

            $this->aiSessionIdleWatchers[$aiSessionId][] = $stream;

            $keepalive = Loop::addPeriodicTimer(15.0, static function () use ($stream): void {
                if ($stream->isWritable()) {
                    $stream->write(": keepalive\n\n");
                }
            });

            $stream->on('close', function () use ($stream, $aiSessionId, $keepalive): void {
                Loop::cancelTimer($keepalive);
                $this->removeAiSessionIdleWatcher($aiSessionId, $stream);
            });
        });

        return Sse::response($stream);
    }

    private function openAiSessionIdleStream(int $aiSessionId): ResponseInterface
    {
        return $this->watchAiSession($aiSessionId, null, '', null);
    }

    private function promoteAiSessionIdleWatchers(int $aiSessionId, string $liveKey): void
    {
        $watchers = $this->aiSessionIdleWatchers[$aiSessionId] ?? [];
        unset($this->aiSessionIdleWatchers[$aiSessionId]);
        if ($watchers === [] || !isset($this->live[$liveKey])) {
            return;
        }

        foreach ($watchers as $sse) {
            if (!$sse->isWritable()) {
                continue;
            }
            $this->live[$liveKey]['hub']->attach($sse, false);
        }
    }

    private function removeAiSessionIdleWatcher(int $aiSessionId, ThroughStream $stream): void
    {
        if (!isset($this->aiSessionIdleWatchers[$aiSessionId])) {
            return;
        }

        $this->aiSessionIdleWatchers[$aiSessionId] = array_values(array_filter(
            $this->aiSessionIdleWatchers[$aiSessionId],
            static fn (ThroughStream $subscriber): bool => $subscriber !== $stream,
        ));
        if ($this->aiSessionIdleWatchers[$aiSessionId] === []) {
            unset($this->aiSessionIdleWatchers[$aiSessionId]);
        }
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
