<?php

declare(strict_types=1);

namespace App\Http;

use App\Chat\AiSessionChatService;
use App\Chat\AiSettingsStore;
use App\Chat\ChatException;
use App\Chat\ChatSettings;
use App\Chat\ChatStopException;
use App\Chat\ChatStreamSession;
use App\Chat\Sse;
use App\Chat\ThreadLock;
use App\Neuron\HttpClient\HttpStreamScope;
use App\Recording\SessionRecorder;
use App\Repository\AiSessionRepository;
use App\Service\AuditService;
use App\Ssh\SshExecBridge;
use App\Ssh\SshLiveRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ThroughStream;
use Throwable;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

final class AiSessionController
{
    public function __construct(
        private readonly AiSessionChatService $chat,
        private readonly AiSessionRepository $aiSessions,
        private readonly ChatSettings $settings,
        private readonly AiSettingsStore $aiSettingsStore,
        private readonly ThreadLock $locks,
        private readonly ChatStreamSession $streamSession,
        private readonly SshExecBridge $execBridge,
        private readonly SshLiveRegistry $liveRegistry,
        private readonly SessionRecorder $recorder,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $username = RequestAuth::username($request);
        $result = await($this->aiSessions->paginate($page, $perPage, $username));

        return $this->ok($result);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $input = $this->input($request);
        $title = trim((string) ($input['title'] ?? ''));
        $username = RequestAuth::username($request);
        $id = (int) await($this->aiSessions->create($username, $title));
        $this->execBridge->registerSession($id, $username);
        $this->audit->logAs(
            $username,
            RequestAuth::clientIp($request),
            'ai.session.created',
            'ai_session',
            $id,
            json_encode(['title' => $title], JSON_UNESCAPED_UNICODE) ?: null,
        );

        return $this->ok(['id' => $id, 'title' => $title, 'status' => 'active']);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id = $this->routeSessionId($request);
        $username = RequestAuth::username($request);
        $session = await($this->aiSessions->findById($id));
        if ($session === null || ($session['username'] ?? '') !== $username) {
            return $this->fail('AI 会话不存在。', 404);
        }
        $segments = await($this->aiSessions->listSegments($id));
        $this->execBridge->registerSession($id, $username, $session['created_at'] ?? null);

        return $this->ok([
            'session' => $session,
            'segments' => $segments,
            'active_segment' => $this->execBridge->getActiveSegment($id),
        ]);
    }

    public function bootstrap(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $id = $this->routeSessionId($request);

            return $this->ok($this->chat->bootstrap(RequestAuth::username($request), $id));
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }
    }

    public function stream(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $id = $this->routeSessionId($request);
            $input = $this->input($request);
            $message = trim((string) ($input['message'] ?? ''));
            if ($message === '') {
                return $this->fail('请输入要说的话', 400);
            }
            if (!$this->settings->isConfigured()) {
                return $this->fail('请先在侧栏「AI 设置」中配置 API Key。', 400);
            }
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);

        return $this->streamLocked($username, $id, $message, function (callable $emit, ?HttpStreamScope $scope) use ($username, $id, $message): array {
            $result = $this->chat->stream($username, $id, $message, $emit, $scope);
            $title = trim($message);
            if ($title !== '') {
                await($this->aiSessions->touch($id, mb_substr($title, 0, 120)));
            }

            return $result;
        });
    }

    public function approvalStream(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $id = $this->routeSessionId($request);
            $input = $this->input($request);
            $approved = $this->bool($input['approved'] ?? false);
            $feedback = trim((string) ($input['feedback'] ?? ''));
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);
        $userLabel = $approved ? '批准' : '拒绝';

        return $this->streamLocked($username, $id, $userLabel, function (callable $emit, ?HttpStreamScope $scope) use ($request, $username, $id, $approved, $feedback): array {
            $result = $this->chat->resumeApproval(
                $username,
                $id,
                $approved,
                $feedback !== '' ? $feedback : null,
                $emit,
                $scope,
            );
            $this->audit->logAs(
                $username,
                RequestAuth::clientIp($request),
                $approved ? 'ai.session.command.approved' : 'ai.session.command.rejected',
                'ai_session',
                $id,
                json_encode(['feedback' => $feedback], JSON_UNESCAPED_UNICODE) ?: null,
            );

            return $result;
        });
    }

    public function feedbackStream(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $id = $this->routeSessionId($request);
            $input = $this->input($request);
            $skip = $this->bool($input['skip'] ?? false);
            $answers = $skip ? [] : $this->answers($input);
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);
        $userLabel = $skip ? '已跳过反馈' : '已提交反馈';

        return $this->streamLocked($username, $id, $userLabel, function (callable $emit, ?HttpStreamScope $scope) use ($username, $id, $answers, $skip): array {
            if ($skip) {
                return $this->chat->skipFeedback($username, $id, $emit, $scope);
            }

            return $this->chat->resumeFeedback($username, $id, $answers, $emit, $scope);
        });
    }

    public function stop(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id = $this->routeSessionId($request);
        $username = RequestAuth::username($request);
        $lockKey = $this->chat->lockKey($username, $id);
        $this->streamSession->requestManualStop($lockKey);

        return $this->ok(['stopped' => true, 'thread_key' => (string) $id]);
    }

    public function subscribeStream(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $id = $this->routeSessionId($request);
            $input = $this->input($request);
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);
        $lockKey = $this->chat->lockKey($username, $id);
        $fromIndex = max(0, (int) ($input['from_index'] ?? 0));
        if (!$this->streamSession->isSubscribeAllowed($lockKey)) {
            return $this->fail('没有进行中的生成', 404);
        }
        if ($this->streamSession->isManuallyStopped($lockKey)) {
            return $this->fail('生成已被手动停止', 409);
        }

        $through = new ThroughStream();

        Loop::futureTick(async(function () use ($through, $lockKey, $fromIndex): void {
            $cursor = $fromIndex;
            $pingAt = microtime(true);
            $idleAttempts = 0;
            try {
                Sse::ping($through);
                while ($through->isWritable()) {
                    $finished = $this->flushStreamEvents($through, $lockKey, $cursor);
                    if ($finished) {
                        return;
                    }

                    if ($this->streamSession->isActive($lockKey)) {
                        $idleAttempts = 0;
                        if (microtime(true) - $pingAt >= 2.0) {
                            Sse::ping($through);
                            $pingAt = microtime(true);
                        }
                        delay(0.05);

                        continue;
                    }

                    if ($this->streamSession->isStreamComplete($lockKey)) {
                        $finished = $this->flushStreamEvents($through, $lockKey, $cursor);
                        if ($finished) {
                            return;
                        }

                        return;
                    }

                    $idleAttempts++;
                    if ($idleAttempts > 40) {
                        return;
                    }
                    delay(0.05);
                }
            } catch (Throwable) {
            } finally {
                Sse::end($through);
            }
        }));

        return Sse::response($through);
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $id = $this->routeSessionId($request);
            $username = RequestAuth::username($request);
            $lockKey = $this->chat->lockKey($username, $id);
            $this->streamSession->requestManualStop($lockKey);
            $this->streamSession->clear($lockKey);
            $this->locks->release($lockKey);
            $this->execBridge->closeSession($id);

            return $this->ok([
                'thread_key' => $this->chat->reset($id),
            ]);
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }
    }

    public function liveStream(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id = $this->routeSessionId($request);
        $username = RequestAuth::username($request);
        $session = await($this->aiSessions->findById($id));
        if ($session === null || ($session['username'] ?? '') !== $username) {
            return $this->fail('AI 会话不存在。', 404);
        }

        $this->execBridge->registerSession($id, $username, $session['created_at'] ?? null);
        $liveKey = $this->execBridge->getActiveLiveKey($id);

        return $this->liveRegistry->watchAiSession(
            $id,
            $liveKey,
            $this->resolveLiveTranscript($id),
            $this->execBridge->getActiveSegment($id),
        );
    }

    public function liveTranscript(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id = $this->routeSessionId($request);
        $username = RequestAuth::username($request);
        $session = await($this->aiSessions->findById($id));
        if ($session === null || ($session['username'] ?? '') !== $username) {
            return $this->fail('AI 会话不存在。', 404);
        }

        $this->execBridge->registerSession($id, $username, $session['created_at'] ?? null);

        return $this->ok([
            'ai_session_id' => $id,
            'transcript' => $this->resolveLiveTranscript($id),
            'active_segment' => $this->execBridge->getActiveSegment($id),
        ]);
    }

    public function recording(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id = $this->routeSessionId($request);
        $username = RequestAuth::username($request);
        $session = await($this->aiSessions->findById($id));
        if ($session === null || ($session['username'] ?? '') !== $username) {
            return $this->fail('AI 会话不存在。', 404);
        }

        $segments = await($this->aiSessions->listSegments($id));
        $manifestSegments = [];
        foreach ($segments as $segment) {
            $sessionId = $segment['session_id'] ?? null;
            $recording = null;
            if ($sessionId !== null && $this->recorder->ensureRecordingAvailable(
                $sessionId,
                $segment['recording_url'] ?? null,
                $segment['start_time'] ?? null,
            )) {
                $recording = [
                    'session_id' => $sessionId,
                    'recording_url' => $segment['recording_url'] ?? ('recordings/' . $sessionId),
                    'manifest_url' => '/api/sessions/' . $sessionId . '/recording',
                ];
            }
            $manifestSegments[] = [
                'order' => $segment['order_index'],
                'segment_id' => $segment['id'],
                'host_id' => $segment['host_id'],
                'host_name' => $segment['host_name'],
                'host_address' => $segment['host_address'],
                'session_id' => $sessionId,
                'started_at' => $segment['started_at'],
                'ended_at' => $segment['ended_at'],
                'recording' => $recording,
            ];
        }

        return $this->ok([
            'ai_session_id' => $id,
            'title' => $session['title'] ?? '',
            'segments' => $manifestSegments,
        ]);
    }

    private function routeSessionId(ServerRequestInterface $request): int
    {
        $id = (int) ($request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            throw new ChatException('缺少 AI 会话 ID');
        }

        return $id;
    }

    private function deny(): ?ResponseInterface
    {
        $this->aiSettingsStore->loadSync();

        if (!$this->settings->isEnabled()) {
            return $this->fail('AI 助手未启用', 503);
        }

        return null;
    }

    /**
     * @param callable(callable(string, array<string, mixed>): void, HttpStreamScope): array<string, mixed> $run
     */
    private function streamLocked(string $username, int $aiSessionId, string $userMessage, callable $run): ResponseInterface
    {
        $lockKey = $this->chat->lockKey($username, $aiSessionId);
        if (!$this->locks->acquire($lockKey)) {
            return $this->fail('上一轮还在生成，请稍等完成后再发', 409);
        }

        $through = new ThroughStream();
        $scope = new HttpStreamScope();

        $threadKey = (string) $aiSessionId;
        $this->streamSession->begin($lockKey, $threadKey, $userMessage);
        $this->streamSession->registerScope($lockKey, $scope);

        Loop::futureTick(async(function () use ($through, $scope, $lockKey, $run): void {
            $emit = function (string $event, array $data) use ($through, $lockKey): void {
                $this->streamSession->append($lockKey, $event, $data);
                if ($through->isWritable()) {
                    $this->locks->heartbeat($lockKey);
                    Sse::write($through, $event, $data);
                }
                if ($this->streamSession->shouldStop($lockKey)) {
                    throw new ChatStopException('manual stop');
                }
            };
            try {
                $done = $run($emit, $scope);
                $this->streamSession->append($lockKey, 'done', $done);
                if ($through->isWritable()) {
                    $this->locks->heartbeat($lockKey);
                    Sse::write($through, 'done', $done);
                }
            } catch (Throwable $e) {
                $errorData = ['message' => $this->publicAiErrorMessage($e)];
                if ($e instanceof ChatException && $e->data !== []) {
                    $errorData = [...$errorData, ...$e->data];
                }
                $this->streamSession->append($lockKey, 'error', $errorData);
                if ($through->isWritable()) {
                    $this->logAiError('stream', $e);
                    Sse::write($through, 'error', $errorData);
                }
            } finally {
                $this->streamSession->finish($lockKey);
                $this->locks->release($lockKey);
                Sse::end($through);
            }
        }));

        return Sse::response($through);
    }

    private function flushStreamEvents(ThroughStream $through, string $lockKey, int &$index): bool
    {
        $events = $this->streamSession->eventsSince($lockKey, $index);
        foreach ($events as $event) {
            if (!$through->isWritable()) {
                return true;
            }
            Sse::write($through, $event['event'], $event['data']);
            $index++;
            if ($event['event'] === 'done' || $event['event'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $query = $request->getQueryParams();
        $raw = (string) $request->getBody();
        $json = json_decode($raw, true);
        if (is_array($json) && $json !== []) {
            return $json;
        }

        return is_array($query) ? $query : [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function answers(array $input): array
    {
        $answers = $input['answers'] ?? null;
        if (!is_array($answers)) {
            throw new ChatException('缺少 answers');
        }

        return $answers;
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) || $value === '1' || $value === 1;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): ResponseInterface
    {
        return Response::json([
            'code' => 0,
            'msg' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fail(string $message, int $status, array $data = []): ResponseInterface
    {
        $body = [
            'code' => $status === 409 ? 409 : 1,
            'msg' => $message,
        ];
        if ($data !== []) {
            $body['data'] = $data;
        }

        return Response::json($body)->withStatus($status);
    }

    private function resolveLiveTranscript(int $aiSessionId): string
    {
        $transcript = $this->execBridge->getLiveTranscript($aiSessionId);
        if ($transcript !== '') {
            return $transcript;
        }

        $transcript = $this->buildTranscriptFromRecordings($aiSessionId);
        if ($transcript !== '') {
            $this->execBridge->seedLiveTranscript($aiSessionId, $transcript);
        }

        return $transcript;
    }

    private function buildTranscriptFromRecordings(int $aiSessionId): string
    {
        $segments = await($this->aiSessions->listSegments($aiSessionId));
        $parts = [];
        foreach ($segments as $segment) {
            $sessionId = isset($segment['session_id']) ? (int) $segment['session_id'] : 0;
            if ($sessionId <= 0) {
                continue;
            }

            $this->recorder->ensureRecordingAvailable(
                $sessionId,
                $segment['recording_url'] ?? null,
                $segment['start_time'] ?? null,
            );
            $manifest = $this->recorder->readManifest(
                $sessionId,
                $segment['recording_url'] ?? null,
                $segment['start_time'] ?? null,
            );
            if ($manifest === null) {
                continue;
            }

            foreach ($manifest['parts'] ?? [] as $part) {
                $name = (string) ($part['name'] ?? '');
                $path = $this->recorder->resolvePartPath(
                    $sessionId,
                    $name,
                    $segment['recording_url'] ?? null,
                    $segment['start_time'] ?? null,
                );
                if ($path === null) {
                    continue;
                }
                $chunk = $this->extractCastOutput($path);
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
            }
        }

        return implode('', $parts);
    }

    private function extractCastOutput(string $path): string
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || $lines === []) {
            return '';
        }

        array_shift($lines);
        $output = '';
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || ($event[1] ?? '') !== 'o') {
                continue;
            }
            $output .= (string) ($event[2] ?? '');
        }

        return $output;
    }

    private function logAiError(string $phase, Throwable $e): void
    {
        $previous = $e->getPrevious();
        $this->logger->error('ai session chat error', [
            'phase' => $phase,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'previous' => $previous instanceof Throwable ? [
                'exception' => $previous::class,
                'message' => $previous->getMessage(),
            ] : null,
        ]);
    }

    private function publicAiErrorMessage(Throwable $e): string
    {
        if ($e instanceof ChatException) {
            return $e->getMessage();
        }

        $previous = $e->getPrevious();
        if ($previous instanceof Throwable && $previous->getMessage() !== '') {
            return 'AI 调用失败：' . $previous->getMessage();
        }

        return 'AI 调用失败：' . $e->getMessage();
    }
}
