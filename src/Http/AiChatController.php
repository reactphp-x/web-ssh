<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\AuditService;
use App\Chat\ChatException;
use App\Chat\ChatService;
use App\Chat\ChatSettings;
use App\Chat\ChatStopException;
use App\Chat\ChatStreamSession;
use App\Chat\Sse;
use App\Chat\ThreadLock;
use App\Neuron\HttpClient\HttpStreamScope;
use App\Ssh\SshSessionBridge;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ThroughStream;
use Throwable;

use function React\Async\async;

final class AiChatController
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatSettings $settings,
        private readonly ThreadLock $locks,
        private readonly ChatStreamSession $streamSession,
        private readonly SshSessionBridge $bridge,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function bootstrap(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $input = $this->input($request);
            $connId = $this->connId($input);

            return $this->ok($this->chat->bootstrap(RequestAuth::username($request), $connId));
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
            $input = $this->input($request);
            $connId = $this->connId($input);
            $message = trim((string) ($input['message'] ?? ''));
            if ($message === '') {
                return $this->fail('请输入要说的话', 400);
            }
            if (!$this->settings->isConfigured()) {
                return $this->fail('请先在 .env 填写 NEURON_AI_KEY。', 400);
            }
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);

        return $this->streamLocked($username, $connId, $message, function (callable $emit, ?HttpStreamScope $scope) use ($username, $connId, $message): array {
            return $this->chat->stream($username, $connId, $message, $emit, $scope);
        });
    }

    public function approvalStream(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $input = $this->input($request);
            $connId = $this->connId($input);
            $approved = $this->bool($input['approved'] ?? false);
            $feedback = trim((string) ($input['feedback'] ?? ''));
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);
        $userLabel = $approved ? '批准' : '拒绝';

        return $this->streamLocked($username, $connId, $userLabel, function (callable $emit, ?HttpStreamScope $scope) use ($request, $username, $connId, $approved, $feedback): array {
            $result = $this->chat->resumeApproval(
                $username,
                $connId,
                $approved,
                $feedback !== '' ? $feedback : null,
                $emit,
                $scope,
            );
            $this->audit->logAs(
                $username,
                RequestAuth::clientIp($request),
                $approved ? 'ai.command.approved' : 'ai.command.rejected',
                'ssh_session',
                null,
                json_encode(['conn_id' => $connId, 'feedback' => $feedback], JSON_UNESCAPED_UNICODE) ?: null,
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
            $input = $this->input($request);
            $connId = $this->connId($input);
            $skip = $this->bool($input['skip'] ?? false);
            $answers = $skip ? [] : $this->answers($input);
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $username = RequestAuth::username($request);
        $userLabel = $skip ? '已跳过反馈' : '已提交反馈';

        return $this->streamLocked($username, $connId, $userLabel, function (callable $emit, ?HttpStreamScope $scope) use ($username, $connId, $answers, $skip): array {
            if ($skip) {
                return $this->chat->skipFeedback($username, $connId, $emit, $scope);
            }

            return $this->chat->resumeFeedback($username, $connId, $answers, $emit, $scope);
        });
    }

    public function stop(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $input = $this->input($request);
            $connId = $this->connId($input);
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }

        $lockKey = $this->chat->lockKey(RequestAuth::username($request), $connId);
        $this->streamSession->requestManualStop($lockKey);

        return $this->ok(['stopped' => true, 'thread_key' => $connId]);
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        try {
            $input = $this->input($request);
            $connId = $this->connId($input);

            return $this->ok([
                'thread_key' => $this->chat->reset($connId),
            ]);
        } catch (ChatException $e) {
            return $this->fail($e->getMessage(), 400, $e->data);
        }
    }

    private function deny(): ?ResponseInterface
    {
        if (!$this->settings->isEnabled()) {
            return $this->fail('AI 助手未启用', 503);
        }

        return null;
    }

    /**
     * @param callable(callable(string, array<string, mixed>): void, HttpStreamScope): array<string, mixed> $run
     */
    private function streamLocked(string $username, string $connId, string $userMessage, callable $run): ResponseInterface
    {
        $lockKey = $this->chat->lockKey($username, $connId);
        if (!$this->locks->acquire($lockKey)) {
            return $this->fail('上一轮还在生成，请稍等完成后再发', 409);
        }

        $through = new ThroughStream();
        $scope = new HttpStreamScope();
        $through->on('close', static function () use ($scope): void {
            $scope->closeAll();
        });

        $this->streamSession->begin($lockKey, $connId, $userMessage);
        $this->streamSession->registerScope($lockKey, $scope);

        Loop::futureTick(async(function () use ($through, $scope, $lockKey, $run): void {
            $emit = function (string $event, array $data) use ($through, $lockKey): void {
                $this->streamSession->append($lockKey, $event, $data);
                if (!$through->isWritable()) {
                    throw new ChatStopException('client disconnected');
                }
                $this->locks->heartbeat($lockKey);
                Sse::write($through, $event, $data);
                if ($this->streamSession->shouldStop($lockKey)) {
                    throw new ChatStopException('manual stop');
                }
            };
            try {
                $done = $run($emit, $scope);
                $this->streamSession->append($lockKey, 'done', $done);
                if ($through->isWritable()) {
                    Sse::write($through, 'done', $done);
                }
            } catch (Throwable $e) {
                if ($through->isWritable() && $e->getMessage() !== 'client disconnected') {
                    $this->logAiError('stream', $e);
                    Sse::write($through, 'error', ['message' => $this->publicAiErrorMessage($e)]);
                }
            } finally {
                $this->streamSession->finish($lockKey);
                $this->locks->release($lockKey);
                Sse::end($through);
            }
        }));

        return Sse::response($through);
    }

    /**
     * @param callable(): ResponseInterface $run
     */
    private function locked(string $username, string $connId, callable $run): ResponseInterface
    {
        $lockKey = $this->chat->lockKey($username, $connId);
        if (!$this->locks->acquire($lockKey)) {
            return $this->fail('上一轮还在生成，请稍等完成后再发', 409);
        }
        try {
            return $run();
        } catch (ChatException $e) {
            $this->logAiError('chat', $e);

            return $this->fail($e->getMessage(), 400, $e->data);
        } catch (Throwable $e) {
            $this->logAiError('chat', $e);

            return $this->fail($this->publicAiErrorMessage($e), 500);
        } finally {
            $this->locks->release($lockKey);
        }
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
     */
    private function connId(array $input): string
    {
        $connId = trim((string) ($input['conn_id'] ?? $input['thread_key'] ?? ''));
        if ($connId === '') {
            throw new ChatException('缺少 conn_id');
        }

        return $connId;
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

    private function logAiError(string $phase, Throwable $e): void
    {
        $previous = $e->getPrevious();
        $this->logger->error('ai chat error', [
            'phase' => $phase,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'previous' => $previous instanceof Throwable ? [
                'exception' => $previous::class,
                'message' => $previous->getMessage(),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
            ] : null,
            'trace' => $e->getTraceAsString(),
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
