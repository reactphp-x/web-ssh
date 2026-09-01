<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Recording\SessionRecorder;
use App\Repository\AiSessionRepository;
use App\Repository\HostRepository;
use App\Service\HostService;
use App\Service\SessionService;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Async\await;
use function React\Promise\reject;

final class SshExecBridge
{
    /** @var array<int, array{
     *     username: string,
     *     active_segment_id: ?int,
     *     active_host_id: ?int,
     *     active_live_key: ?string,
     *     segments: array<int, array{
     *         segment_id: int,
     *         host_id: int,
     *         session_id: int,
     *         live_key: string,
     *         workspace: OpenSshWorkspace,
     *         output: string,
     *         host_name: string,
     *         host_address: string,
     *     }>
     * }> */
    private array $sessions = [];

    public function __construct(
        private readonly HostService $hostService,
        private readonly HostRepository $hosts,
        private readonly AiSessionRepository $aiSessions,
        private readonly SessionService $sessionService,
        private readonly ?SshLiveRegistry $liveRegistry = null,
        private readonly ?SessionRecorder $recorder = null,
        private readonly ?AiSessionLiveTranscript $liveTranscript = null,
    ) {
    }

    public function isActive(int $aiSessionId): bool
    {
        return isset($this->sessions[$aiSessionId]);
    }

    public function isOwnedBy(int $aiSessionId, string $username): bool
    {
        return ($this->sessions[$aiSessionId]['username'] ?? '') === $username;
    }

    public function registerSession(int $aiSessionId, string $username, ?string $createdAt = null): void
    {
        if (!isset($this->sessions[$aiSessionId])) {
            $this->sessions[$aiSessionId] = [
                'username' => $username,
                'active_segment_id' => null,
                'active_host_id' => null,
                'active_live_key' => null,
                'segments' => [],
            ];
        }

        $this->liveTranscript?->rememberCreatedAt($aiSessionId, $createdAt);
    }

    public function getActiveLiveKey(int $aiSessionId): ?string
    {
        return $this->sessions[$aiSessionId]['active_live_key'] ?? null;
    }

    /**
     * @return array{segment_id: int, host_id: int, live_key: string, host_name: string, host_address: string}|null
     */
    public function getActiveSegment(int $aiSessionId): ?array
    {
        $entry = $this->sessions[$aiSessionId] ?? null;
        if ($entry === null || $entry['active_segment_id'] === null) {
            return null;
        }

        $segment = $entry['segments'][$entry['active_segment_id']] ?? null;
        if ($segment === null) {
            return null;
        }

        return [
            'segment_id' => $segment['segment_id'],
            'host_id' => $segment['host_id'],
            'live_key' => $segment['live_key'],
            'host_name' => $segment['host_name'],
            'host_address' => $segment['host_address'],
        ];
    }

    public function getLiveTranscript(int $aiSessionId): string
    {
        $saved = $this->liveTranscript?->read($aiSessionId) ?? '';
        $entry = $this->sessions[$aiSessionId] ?? null;
        if ($entry === null) {
            return $saved;
        }

        $memory = '';
        foreach ($entry['segments'] as $segment) {
            $memory .= $segment['output'];
        }

        if ($saved !== '' && strlen($saved) >= strlen($memory)) {
            return $saved;
        }

        return $memory !== '' ? $memory : $saved;
    }

    public function clearLiveTranscript(int $aiSessionId): void
    {
        $this->liveTranscript?->clear($aiSessionId);
    }

    public function seedLiveTranscript(int $aiSessionId, string $data): void
    {
        if ($data === '' || ($this->liveTranscript?->read($aiSessionId) ?? '') !== '') {
            return;
        }

        $this->liveTranscript?->write($aiSessionId, $data);
    }

    /**
     * @return array{segment_id: int, host_id: int, live_key: string, host_name: string, switched: bool}
     */
    public function ensureSegment(int $aiSessionId, string $username, int $hostId): array
    {
        $this->registerSession($aiSessionId, $username);

        $entry = &$this->sessions[$aiSessionId];
        if ($entry['active_host_id'] === $hostId && $entry['active_segment_id'] !== null) {
            $segment = $entry['segments'][$entry['active_segment_id']];

            return [
                'segment_id' => $segment['segment_id'],
                'host_id' => $segment['host_id'],
                'live_key' => $segment['live_key'],
                'host_name' => $segment['host_name'],
                'switched' => false,
            ];
        }

        if ($entry['active_segment_id'] !== null) {
            $this->closeSegment($aiSessionId, $entry['active_segment_id']);
        }

        $host = await($this->hosts->findById($hostId));
        if ($host === null) {
            throw new RuntimeException('主机不存在：' . $hostId);
        }

        $target = await($this->hostService->resolveSshTarget($host));
        $workspace = OpenSshWorkspace::prepare($target, Ssh2Client::connectTimeout());

        $sessionId = (int) await($this->sessionService->start($username, $hostId, 'ai_exec', $aiSessionId));
        await($this->sessionService->markConnected($sessionId));

        $orderIndex = (int) await($this->aiSessions->countSegments($aiSessionId));
        $segmentId = (int) await($this->aiSessions->createSegment(
            $aiSessionId,
            $hostId,
            'pending',
            $orderIndex,
            $sessionId,
        ));
        $liveKey = $this->makeLiveKey($aiSessionId, $segmentId);
        await($this->aiSessions->updateLiveKey($segmentId, $liveKey));
        await($this->aiSessions->setActiveSegment($aiSessionId, $segmentId));

        $hostName = (string) ($host['name'] ?? '');
        $hostAddress = (string) ($host['address'] ?? '');

        $this->liveRegistry?->registerAiSegment(
            $liveKey,
            $username,
            $hostId,
            [
                'name' => $hostName,
                'address' => $hostAddress,
                'port' => (int) ($host['port'] ?? 22),
                'username' => (string) ($host['username'] ?? ''),
            ],
            $sessionId,
            $aiSessionId,
            $segmentId,
        );

        $title = $hostName !== '' ? $hostName : $hostAddress;
        $this->recorder?->start($sessionId, 120, 40, $title . ' [AI]');
        $this->liveRegistry?->markConnected($liveKey);
        $this->liveRegistry?->writeResize($liveKey, 120, 40);

        $entry['segments'][$segmentId] = [
            'segment_id' => $segmentId,
            'host_id' => $hostId,
            'session_id' => $sessionId,
            'live_key' => $liveKey,
            'workspace' => $workspace,
            'output' => '',
            'host_name' => $hostName,
            'host_address' => $hostAddress,
        ];
        $entry['active_segment_id'] = $segmentId;
        $entry['active_host_id'] = $hostId;
        $entry['active_live_key'] = $liveKey;

        if ($this->liveRegistry !== null) {
            $this->liveRegistry->writeAiSegmentSwitch($liveKey, [
                'ai_session_id' => $aiSessionId,
                'segment_id' => $segmentId,
                'host_id' => $hostId,
                'host_name' => $hostName,
                'host_address' => $hostAddress,
            ]);
        }

        $this->publishAiChunk(
            $aiSessionId,
            $segmentId,
            $liveKey,
            $sessionId,
            self::formatSegmentHostBanner($hostName, $hostAddress),
        );

        return [
            'segment_id' => $segmentId,
            'host_id' => $hostId,
            'live_key' => $liveKey,
            'host_name' => $hostName,
            'switched' => true,
        ];
    }

    public function getRecentOutput(int $aiSessionId, int $hostId, int $maxChars = 4000): string
    {
        $entry = $this->sessions[$aiSessionId] ?? null;
        if ($entry === null) {
            return '';
        }

        foreach ($entry['segments'] as $segment) {
            if ($segment['host_id'] !== $hostId) {
                continue;
            }
            $output = $segment['output'];
            $stripped = CommandOutputCollector::stripAnsi($output);
            if (strlen($stripped) <= $maxChars) {
                return trim($stripped);
            }

            return trim(substr($stripped, -$maxChars));
        }

        return '';
    }

    public function runCommand(int $aiSessionId, string $username, int $hostId, string $command, int $timeoutSec): PromiseInterface
    {
        $segmentInfo = $this->ensureSegment($aiSessionId, $username, $hostId);
        $segmentId = $segmentInfo['segment_id'];
        $segment = $this->sessions[$aiSessionId]['segments'][$segmentId] ?? null;
        if ($segment === null) {
            return reject(new RuntimeException('AI 会话分段未就绪。'));
        }

        $command = trim($command);
        if ($command === '') {
            return reject(new RuntimeException('命令不能为空。'));
        }

        if ($this->isBlockedCommand($command)) {
            return reject(new RuntimeException('该命令被禁止通过 AI 执行（交互式/TUI 命令）。'));
        }

        $liveKey = $segment['live_key'];
        $sessionId = $segment['session_id'];
        $this->publishAiChunk($aiSessionId, $segmentId, $liveKey, $sessionId, self::formatLiveHeader($command));

        return SshExecRunner::run(
            $segment['workspace'],
            $command,
            $timeoutSec,
            120,
            40,
            function (string $chunk) use ($aiSessionId, $segmentId, $liveKey, $sessionId): void {
                $this->publishAiChunk($aiSessionId, $segmentId, $liveKey, $sessionId, $chunk);
            },
        )->then(function (CommandResult $result) use ($aiSessionId, $segmentId, $liveKey, $sessionId): CommandResult {
            $this->publishAiChunk(
                $aiSessionId,
                $segmentId,
                $liveKey,
                $sessionId,
                self::formatLiveFooter($result->exitCode, $result->timedOut),
            );
            $this->recorder?->syncManifest($sessionId);

            return $result;
        });
    }

    public function closeSession(int $aiSessionId): void
    {
        $entry = $this->sessions[$aiSessionId] ?? null;
        if ($entry === null) {
            return;
        }

        if ($entry['active_segment_id'] !== null) {
            $this->closeSegment($aiSessionId, $entry['active_segment_id']);
        }

        $this->clearLiveTranscript($aiSessionId);
        unset($this->sessions[$aiSessionId]);
    }

    private function closeSegment(int $aiSessionId, int $segmentId): void
    {
        $entry = &$this->sessions[$aiSessionId];
        $segment = $entry['segments'][$segmentId] ?? null;
        if ($segment === null) {
            return;
        }

        $segment['workspace']->cleanup();
        $this->recorder?->finish($segment['session_id']);
        await($this->sessionService->markClosed($segment['session_id']));
        await($this->aiSessions->finishSegment($segmentId));
        $this->liveRegistry?->finish($segment['live_key'], 'disconnected', 'AI 分段已结束');

        unset($entry['segments'][$segmentId]);
        if ($entry['active_segment_id'] === $segmentId) {
            $entry['active_segment_id'] = null;
            $entry['active_host_id'] = null;
            $entry['active_live_key'] = null;
        }
    }

    private function publishAiChunk(
        int $aiSessionId,
        int $segmentId,
        string $liveKey,
        int $sessionId,
        string $chunk,
    ): void {
        if ($chunk === '') {
            return;
        }

        $terminalChunk = CommandOutputCollector::normalizeTerminalNewlines($chunk);
        $entry = &$this->sessions[$aiSessionId];
        if (isset($entry['segments'][$segmentId])) {
            $entry['segments'][$segmentId]['output'] .= $terminalChunk;
            $maxBytes = 65536;
            if (strlen($entry['segments'][$segmentId]['output']) > $maxBytes) {
                $entry['segments'][$segmentId]['output'] = substr(
                    $entry['segments'][$segmentId]['output'],
                    -$maxBytes,
                );
            }
        }

        $this->liveRegistry?->writeOutput($liveKey, $terminalChunk);
        $this->recorder?->writeOutput($sessionId, $terminalChunk);
        $this->liveTranscript?->append($aiSessionId, $terminalChunk);
    }

    private function makeLiveKey(int $aiSessionId, int $segmentId): string
    {
        return sprintf('%08x%08x', $aiSessionId, $segmentId);
    }

    private static function formatLiveHeader(string $command): string
    {
        return "\r\n\x1b[33m[AI]\x1b[0m \x1b[32m$\x1b[0m " . $command . "\r\n";
    }

    private static function formatSegmentHostBanner(string $hostName, string $hostAddress): string
    {
        $label = $hostName !== '' ? $hostName : $hostAddress;
        if ($hostName !== '' && $hostAddress !== '' && $hostName !== $hostAddress) {
            $label = $hostName . ' (' . $hostAddress . ')';
        }

        return "\r\n\x1b[90m────────── 主机 · " . $label . " ──────────\x1b[0m\r\n";
    }

    private static function formatLiveFooter(?int $exitCode, bool $timedOut): string
    {
        if ($timedOut) {
            return "\x1b[33m[AI]\x1b[0m (timeout)\r\n";
        }

        return "\x1b[33m[AI]\x1b[0m exit " . ($exitCode ?? '?') . "\r\n";
    }

    private function isBlockedCommand(string $command): bool
    {
        // Temporarily disabled: substring blocklist false-positive on long scripts.
        // Re-enable via CommandBlocklist (first-command detection) when needed.
        return false;
    }
}
