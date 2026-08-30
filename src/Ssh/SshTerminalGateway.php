<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Http\RequestAuth;
use App\Service\HostService;
use App\Service\SessionService;
use App\Support\WorkerLog;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop as ReactLoop;
use ReactphpX\ConnectionGroup\ConnectionGroup;
use ReactphpX\WebsocketMiddleware\ConnectionInterface;
use Throwable;

final class SshTerminalGateway
{
    /** @var array<string, SshTerminalSession> */
    private array $sessions = [];

    /** @var array<string, array{host_id: int, host: array<string, mixed>, username: string, session_id: ?int}> */
    private array $pending = [];

    /** @var array<string, int> */
    private array $connectionSessions = [];

    public function __construct(
        private readonly ConnectionGroup $connectionGroup,
        private readonly HostService $hostService,
        private readonly SessionService $sessionService,
        private readonly SshLiveRegistry $liveRegistry,
    ) {
    }

    public function register(): void
    {
        $this->connectionGroup->on('open', function (ConnectionInterface $conn, ServerRequestInterface $request): void {
            $this->handleOpen($conn, $request);
        });

        $this->connectionGroup->on('message', function (ConnectionInterface $from, string $msg): void {
            $this->handleMessage($from, $msg);
        });

        $this->connectionGroup->on('close', function (ConnectionInterface $conn): void {
            $this->handleClose($conn);
        });
    }

    private function handleOpen(ConnectionInterface $conn, ServerRequestInterface $request): void
    {
        $hostId = (int) ($request->getQueryParams()['hostId'] ?? 0);
        if ($hostId <= 0) {
            $this->sendErrorAndClose($conn, [
                'type' => 'error',
                'message' => 'Missing or invalid hostId.',
            ]);

            return;
        }

        $username = RequestAuth::username($request);

        $this->hostService->repository()->findById($hostId)->then(
            function (?array $host) use ($conn, $hostId, $username): void {
                if ($host === null) {
                    $this->sendErrorAndClose($conn, [
                        'type' => 'error',
                        'message' => '主机不存在或已被删除。',
                    ]);

                    return;
                }

                $this->pending[$conn->_id] = [
                    'host_id' => $hostId,
                    'host' => $host,
                    'username' => $username,
                    'session_id' => null,
                ];

                $this->liveRegistry->registerPending($conn->_id, $username, $hostId, $host);

                WorkerLog::info(sprintf(
                    'WebSocket open conn=%s target=%s@%s:%d hostId=%d user=%s',
                    $conn->_id,
                    $host['username'],
                    $host['address'],
                    $host['port'],
                    $hostId,
                    $username,
                ));

                $this->sendJson($conn, [
                    'type' => 'ready',
                    '_id' => $conn->_id,
                    'host' => [
                        'id' => $host['id'],
                        'name' => $host['name'],
                        'address' => $host['address'],
                        'port' => $host['port'],
                    ],
                    'message' => 'Send {"type":"auth"} to connect using stored credentials.',
                ]);
            },
            function (Throwable $error) use ($conn): void {
                $this->sendErrorAndClose($conn, [
                    'type' => 'error',
                    'message' => '加载主机配置失败: ' . $error->getMessage(),
                ]);
            },
        );
    }

    private function handleMessage(ConnectionInterface $from, string $msg): void
    {
        $payload = json_decode($msg, true);
        if (!is_array($payload)) {
            $this->sendJson($from, ['type' => 'error', 'message' => 'Invalid JSON message.']);

            return;
        }

        $type = (string) ($payload['type'] ?? '');

        if ($type === 'auth') {
            $this->authenticate($from, $payload);

            return;
        }

        $session = $this->sessions[$from->_id] ?? null;
        if ($session === null) {
            $this->sendJson($from, ['type' => 'error', 'message' => 'SSH session is not connected.']);

            return;
        }

        if ($type === 'input') {
            $session->write((string) ($payload['data'] ?? ''));

            return;
        }

        if ($type === 'resize') {
            $cols = max(1, (int) ($payload['cols'] ?? 80));
            $rows = max(1, (int) ($payload['rows'] ?? 24));
            $this->liveRegistry->writeResize($from->_id, $cols, $rows);
            $session->resize($cols, $rows);

            return;
        }

        $this->sendJson($from, ['type' => 'error', 'message' => 'Unknown message type.']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authenticate(ConnectionInterface $conn, array $payload): void
    {
        if (isset($this->sessions[$conn->_id])) {
            $this->sendJson($conn, ['type' => 'error', 'message' => 'SSH session already started.']);

            return;
        }

        $pending = $this->pending[$conn->_id] ?? null;
        if ($pending === null) {
            $this->sendJson($conn, ['type' => 'error', 'message' => 'Connection is not ready for authentication.']);

            return;
        }

        WorkerLog::info(sprintf(
            'SSH auth conn=%s target=%s@%s:%d hostId=%d jump=%s',
            $conn->_id,
            $pending['host']['username'],
            $pending['host']['address'],
            $pending['host']['port'],
            $pending['host_id'],
            (string) ($pending['host']['jump_host_name'] ?? $pending['host']['jump_host_id'] ?? ''),
        ));

        $this->hostService->resolveSshTarget($pending['host'])->then(
            function (SshTarget $target) use ($conn, $pending, $payload): void {
                $this->sessionService->start($pending['username'], $pending['host_id'])->then(
                    function (int $sessionId) use ($conn, $pending, $target, $payload): void {
                        $pending['session_id'] = $sessionId;
                        $this->pending[$conn->_id] = $pending;
                        $this->liveRegistry->markSessionStarted($conn->_id, $sessionId);

                        $cols = max(1, (int) ($payload['cols'] ?? 80));
                        $rows = max(1, (int) ($payload['rows'] ?? 24));
                        $this->liveRegistry->writeResize($conn->_id, $cols, $rows);

                        $session = new SshTerminalSession();
                        $this->sessions[$conn->_id] = $session;

                        $session->connect(
                            $target,
                            onOutput: function (string $chunk) use ($conn): void {
                                $this->liveRegistry->writeOutput($conn->_id, $chunk);
                                $this->sendJson($conn, [
                                    'type' => 'output',
                                    'data' => base64_encode($chunk),
                                ]);
                            },
                            onConnected: function () use ($conn, $pending, $sessionId): void {
                                unset($this->pending[$conn->_id]);
                                $this->connectionSessions[$conn->_id] = $sessionId;
                                $this->sessionService->markConnected($sessionId);
                                $this->hostService->repository()->touchLastConnected($pending['host_id']);

                                WorkerLog::info(sprintf(
                                    'SSH connected conn=%s target=%s@%s:%d session=%d',
                                    $conn->_id,
                                    $pending['host']['username'],
                                    $pending['host']['address'],
                                    $pending['host']['port'],
                                    $sessionId,
                                ));

                                $this->liveRegistry->markConnected($conn->_id);

                                $this->sendJson($conn, [
                                    'type' => 'connected',
                                    'host' => $pending['host']['address'],
                                    'user' => $pending['host']['username'],
                                    'port' => $pending['host']['port'],
                                    'name' => $pending['host']['name'],
                                    'session_id' => $sessionId,
                                ]);
                            },
                            onError: function (Throwable $exception) use ($conn, $pending, $target, $sessionId): void {
                                unset($this->sessions[$conn->_id], $this->pending[$conn->_id]);

                                $verbose = getenv('APP_ENV') === 'development';
                                $error = SshErrorFormatter::format($exception, $target, $verbose);

                                $this->sessionService->markFailed($sessionId, $error['message']);

                                WorkerLog::error(sprintf(
                                    'SSH failed conn=%s target=%s@%s:%d',
                                    $conn->_id,
                                    $pending['host']['username'],
                                    $pending['host']['address'],
                                    $pending['host']['port'],
                                ));
                                WorkerLog::error($error['detail']);

                                $this->liveRegistry->writeError($conn->_id, [
                                    'message' => $error['message'],
                                    'detail' => $error['detail'],
                                ]);

                                $this->sendErrorAndClose($conn, [
                                    'type' => 'error',
                                    'message' => $error['message'],
                                    'detail' => $error['detail'],
                                    'exception' => $error['exception'],
                                ]);
                            },
                            onExit: function () use ($conn): void {
                                $session = $this->sessions[$conn->_id] ?? null;
                                if ($session === null) {
                                    return;
                                }

                                unset($this->sessions[$conn->_id]);

                                if (isset($this->connectionSessions[$conn->_id])) {
                                    $this->sessionService->markClosed($this->connectionSessions[$conn->_id]);
                                    unset($this->connectionSessions[$conn->_id]);
                                }

                                WorkerLog::info(sprintf('SSH exited conn=%s', $conn->_id));

                                $this->liveRegistry->finish($conn->_id, 'disconnected', 'SSH 会话已结束');

                                $this->sendJson($conn, [
                                    'type' => 'disconnected',
                                    'message' => 'SSH 会话已结束',
                                ]);

                                ReactLoop::addTimer(0.1, static function () use ($conn): void {
                                    $conn->close();
                                });
                            },
                            cols: $cols,
                            rows: $rows,
                        );
                    },
                );
            },
            function (Throwable $error) use ($conn): void {
                $this->sendErrorAndClose($conn, [
                    'type' => 'error',
                    'message' => $error->getMessage(),
                ]);
            },
        );
    }

    private function handleClose(ConnectionInterface $conn): void
    {
        $pending = $this->pending[$conn->_id] ?? null;
        if ($pending !== null && ($pending['session_id'] ?? null) !== null) {
            $this->sessionService->markFailed((int) $pending['session_id'], '连接中断');
        }

        unset($this->pending[$conn->_id]);

        $this->liveRegistry->finish($conn->_id, 'disconnected', '连接中断');

        if (isset($this->connectionSessions[$conn->_id])) {
            $this->sessionService->markClosed($this->connectionSessions[$conn->_id]);
            unset($this->connectionSessions[$conn->_id]);
        }

        $session = $this->sessions[$conn->_id] ?? null;
        if ($session !== null) {
            WorkerLog::info(sprintf('WebSocket close conn=%s', $conn->_id));
            $session->close();
            unset($this->sessions[$conn->_id]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJson(ConnectionInterface $conn, array $payload): void
    {
        $this->connectionGroup->send($conn, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendErrorAndClose(ConnectionInterface $conn, array $payload): void
    {
        $this->sendJson($conn, $payload);

        ReactLoop::addTimer(0.1, static function () use ($conn): void {
            $conn->close();
        });
    }
}
