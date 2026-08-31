<?php

declare(strict_types=1);

namespace App\Chat;

use App\Neuron\HttpClient\HttpStreamScope;
use App\Neuron\HttpClient\ReactHttpClient;
use App\Neuron\OrchestratorAgent;
use App\Neuron\Tools\GetCommandContextTool;
use App\Neuron\Tools\ListHostsTool;
use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Neuron\Workflow\FeedbackField;
use App\Neuron\Workflow\FeedbackRequest;
use App\Repository\AiSessionRepository;
use App\Repository\HostRepository;
use App\Ssh\OrchestratorToolContext;
use App\Ssh\SshExecBridge;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\FilePersistence;
use Psr\Log\LoggerInterface;
use Throwable;

use function React\Async\await;

final class AiSessionChatService
{
    public function __construct(
        private readonly ChatSettings $settings,
        private readonly SshExecBridge $execBridge,
        private readonly HostRepository $hosts,
        private readonly AiSessionRepository $aiSessions,
        private readonly HttpClientInterface $httpClient,
        private readonly StreamChunkMapper $chunks,
        private readonly ChatStreamSession $streamSession,
        private readonly StoppedTurnWriter $stoppedTurns,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function lockKey(string $username, int $aiSessionId): string
    {
        return sha1($username . ':ai_session:' . $aiSessionId);
    }

    private function threadKey(int $aiSessionId): string
    {
        return (string) $aiSessionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(string $username, int $aiSessionId): array
    {
        $this->assertAiSessionAccess($username, $aiSessionId);
        $this->ensureDirectories();
        $key = $this->threadKey($aiSessionId);
        $lockKey = $this->lockKey($username, $aiSessionId);

        return [
            'configured' => $this->settings->isConfigured(),
            'enabled' => $this->settings->isEnabled(),
            'model' => $this->settings->model(),
            'thread_key' => $key,
            'ai_session_id' => $aiSessionId,
            'messages' => $this->loadMessages($aiSessionId),
            'tool_calls' => $this->loadToolCalls($aiSessionId),
            'timeline' => $this->loadTimeline($aiSessionId),
            'approval' => $this->loadApproval($aiSessionId),
            'feedback' => $this->loadFeedback($aiSessionId),
            'generation' => $this->streamSession->getMeta($lockKey),
            'urls' => [
                'stream' => '/api/ai/sessions/' . $aiSessionId . '/chat/stream',
                'subscribe' => '/api/ai/sessions/' . $aiSessionId . '/chat/stream/subscribe',
                'stop' => '/api/ai/sessions/' . $aiSessionId . '/stop',
                'approval' => '/api/ai/sessions/' . $aiSessionId . '/approval/stream',
                'feedback' => '/api/ai/sessions/' . $aiSessionId . '/feedback/stream',
                'reset' => '/api/ai/sessions/' . $aiSessionId . '/reset',
                'bootstrap' => '/api/ai/sessions/' . $aiSessionId . '/bootstrap',
            ],
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): void $emit
     * @return array<string, mixed>
     */
    public function stream(
        string $username,
        int $aiSessionId,
        string $message,
        callable $emit,
        ?HttpStreamScope $scope = null,
    ): array {
        $message = trim($message);
        $key = $this->threadKey($aiSessionId);
        $emit('start', [
            'thread_key' => $key,
            'user_html' => $this->toHtml($message),
        ]);

        return $this->invoke($username, $aiSessionId, $message, null, $emit, $scope);
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    public function resumeApproval(
        string $username,
        int $aiSessionId,
        bool $approved,
        ?string $feedback = null,
        ?callable $emit = null,
        ?HttpStreamScope $scope = null,
    ): array {
        $token = $this->workflowResumeToken($aiSessionId);
        $request = $this->loadApprovalRequest($token);
        if ($request === null) {
            throw new ChatException('没有待审核的操作。');
        }

        foreach ($request->getActions() as $action) {
            if (!$action->isPending()) {
                continue;
            }
            if ($approved) {
                $action->approve($feedback);
            } else {
                $action->reject($feedback ?: '用户已拒绝');
            }
        }

        $userLabel = $approved ? '批准' : '拒绝';
        if ($emit) {
            $emit('start', [
                'thread_key' => $this->threadKey($aiSessionId),
                'user_html' => $this->toHtml($userLabel),
            ]);
        }

        return $this->invoke($username, $aiSessionId, $userLabel, $request, $emit, $scope);
    }

    /**
     * @param array<string, mixed> $answers
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    public function resumeFeedback(
        string $username,
        int $aiSessionId,
        array $answers,
        ?callable $emit = null,
        ?HttpStreamScope $scope = null,
    ): array {
        $token = $this->workflowResumeToken($aiSessionId);
        $request = $this->loadFeedbackRequest($token);
        if ($request === null) {
            throw new ChatException('没有待回答的问题。');
        }

        foreach ($request->getFields() as $field) {
            if (array_key_exists($field->id, $answers)) {
                $request->setFieldValue($field->id, $answers[$field->id]);
            }
        }

        foreach ($request->getPendingFields() as $field) {
            if ($field->required) {
                throw new ChatException('请填写必填项：' . $field->label);
            }
        }

        $userLabel = $this->formatFeedbackSummaryText($this->buildFeedbackSummaryItems($request));
        if ($userLabel === '') {
            $userLabel = '已提交反馈';
        }

        if ($emit) {
            $emit('start', [
                'thread_key' => $this->threadKey($aiSessionId),
                'user_html' => $this->toHtml($userLabel),
            ]);
        }

        return $this->invoke($username, $aiSessionId, $userLabel, $request, $emit, $scope);
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    public function skipFeedback(
        string $username,
        int $aiSessionId,
        ?callable $emit = null,
        ?HttpStreamScope $scope = null,
    ): array {
        $token = $this->workflowResumeToken($aiSessionId);
        $request = $this->loadFeedbackRequest($token);
        if ($request === null) {
            throw new ChatException('没有待回答的问题。');
        }

        foreach ($request->getFields() as $field) {
            $request->setFieldValue($field->id, '（已跳过）');
        }

        $userLabel = '已跳过反馈';
        if ($emit) {
            $emit('start', [
                'thread_key' => $this->threadKey($aiSessionId),
                'user_html' => $this->toHtml($userLabel),
            ]);
        }

        return $this->invoke($username, $aiSessionId, $userLabel, $request, $emit, $scope);
    }

    public function reset(int $aiSessionId): string
    {
        $this->deleteChatArtifacts($aiSessionId);
        try {
            $this->workflowPersistence()->delete($this->workflowResumeToken($aiSessionId));
        } catch (Throwable) {
        }

        return $this->threadKey($aiSessionId) . '-' . time();
    }

    private function deleteChatArtifacts(int $aiSessionId): void
    {
        $paths = $this->settings->aiSessionStoragePaths();
        $createdAt = $this->sessionCreatedAt($aiSessionId);

        $chatFile = $paths->chatDirectory($aiSessionId, $createdAt)
            . DIRECTORY_SEPARATOR
            . 'neuron_'
            . $aiSessionId
            . '.chat';
        if (is_file($chatFile)) {
            @unlink($chatFile);
        }

        $liveLog = $paths->liveLogPath($aiSessionId, $createdAt);
        if (is_file($liveLog)) {
            @unlink($liveLog);
        }

        $base = rtrim($this->settings->aiSessionStoragePath(), '/');
        $legacyChat = $base . '/neuron_' . $aiSessionId . '.chat';
        if (is_file($legacyChat)) {
            @unlink($legacyChat);
        }
        $legacyLive = $base . '/live/' . $aiSessionId . '.log';
        if (is_file($legacyLive)) {
            @unlink($legacyLive);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadApproval(int $aiSessionId): ?array
    {
        $request = $this->loadApprovalRequest($this->workflowResumeToken($aiSessionId));
        if ($request === null || $request->getPendingActions() === []) {
            return null;
        }

        return $this->serializeApprovalRequest($request, $this->workflowResumeToken($aiSessionId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadFeedback(int $aiSessionId): ?array
    {
        $request = $this->loadFeedbackRequest($this->workflowResumeToken($aiSessionId));
        if ($request === null || $request->getPendingFields() === []) {
            return null;
        }

        return $this->serializeFeedbackRequest($request, $this->workflowResumeToken($aiSessionId));
    }

    public function repairIncompleteToolCalls(int $aiSessionId): void
    {
        try {
            $this->fileHistory($aiSessionId)->repairIncompleteToolCalls(true);
        } catch (Throwable) {
        }
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    private function invoke(
        string $username,
        int $aiSessionId,
        string $userMessage,
        ?InterruptRequest $resume,
        ?callable $emit,
        ?HttpStreamScope $scope,
    ): array {
        $this->assertAiSessionAccess($username, $aiSessionId);

        $userMessage = trim($userMessage);
        if ($resume === null && $userMessage === '') {
            throw new ChatException('请输入要说的话');
        }

        if ($resume === null && $this->loadApproval($aiSessionId) !== null) {
            $pending = $this->loadApproval($aiSessionId);
            throw new ChatException('有待审核的命令，请先批准或拒绝。', data: [
                'approval' => $pending,
                'thread_key' => $this->threadKey($aiSessionId),
            ]);
        }

        if ($resume === null && $this->loadFeedback($aiSessionId) !== null) {
            $pending = $this->loadFeedback($aiSessionId);
            throw new ChatException('有待回答的问题，可先提交、跳过反馈，或填写后提交。', data: [
                'feedback' => $pending,
                'thread_key' => $this->threadKey($aiSessionId),
            ]);
        }

        if (!$this->settings->isEnabled()) {
            throw new ChatException('AI 助手未启用。');
        }
        if (!$this->settings->isConfigured()) {
            throw new ChatException('请先在侧栏「AI 设置」中配置 API Key。');
        }

        $this->ensureDirectories();
        $this->fileHistory($aiSessionId)->repairIncompleteToolCalls($resume === null);

        $agent = $this->makeAgent($aiSessionId, $username, $scope);
        $assembled = '';
        $lockKey = $this->lockKey($username, $aiSessionId);

        try {
            if ($emit !== null) {
                $handler = $resume !== null
                    ? $agent->stream([], $resume)
                    : $agent->stream(new UserMessage($userMessage));
                foreach ($handler->events() as $chunk) {
                    if ($this->streamSession->shouldStop($lockKey)) {
                        throw new ChatStopException('manual stop');
                    }
                    $mapped = $this->chunks->map($chunk);
                    if ($mapped === null) {
                        continue;
                    }
                    if ($mapped['event'] === 'delta') {
                        $assembled .= (string) ($mapped['data']['text'] ?? '');
                    }
                    $emit($mapped['event'], $mapped['data']);
                }
                $content = trim($assembled);
                try {
                    $reply = $handler->getMessage();
                    $fromReply = method_exists($reply, 'getContent') ? trim((string) $reply->getContent()) : '';
                    if ($content === '' && $fromReply !== '') {
                        $content = $fromReply;
                    }
                } catch (Throwable) {
                }
            } else {
                $handler = $resume !== null
                    ? $agent->chat([], $resume)
                    : $agent->chat(new UserMessage($userMessage));
                $reply = $handler->getMessage();
                $content = trim((string) $reply->getContent());
            }
        } catch (ChatStopException) {
            return $this->finalizeStoppedResponse($aiSessionId, $userMessage, $assembled);
        } catch (WorkflowInterrupt $interrupt) {
            return $this->formatInterrupt($interrupt, $aiSessionId, $userMessage, $emit, trim($assembled));
        } catch (Throwable $e) {
            if ($emit !== null && $this->streamSession->wasManualStop($lockKey)) {
                return $this->finalizeStoppedResponse($aiSessionId, $userMessage, $assembled);
            }
            $this->logger->error('ai session chat failed', [
                'ai_session_id' => $aiSessionId,
                'username' => $username,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new ChatException('AI 调用失败：' . $e->getMessage(), 0, $e);
        }

        $content = $content !== '' ? $content : trim($assembled);

        if ($this->streamSession->wasManualStop($lockKey)) {
            return $this->finalizeStoppedResponse($aiSessionId, $userMessage, $assembled);
        }

        return $this->payload($content, $userMessage, $aiSessionId, null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeStoppedResponse(
        int $aiSessionId,
        string $userMessage,
        string $assembled,
    ): array {
        $content = $this->stoppedTurns->saveManualStop(
            $this->fileHistory($aiSessionId),
            $userMessage,
            $assembled,
            true,
        );

        return array_merge($this->payload($content, $userMessage, $aiSessionId, null, null), ['stopped' => true]);
    }

    private function persistAssistantPartial(int $aiSessionId, string $content): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }

        $history = $this->fileHistory($aiSessionId);
        $messages = $history->getMessages();
        $last = $messages !== [] ? $messages[array_key_last($messages)] : null;
        if ($last instanceof AssistantMessage) {
            $existing = trim((string) $last->getContent());
            if ($existing === '' || mb_strlen($content) >= mb_strlen($existing)) {
                $history->replaceLastAssistantContent($content);
            }

            return;
        }

        $history->addMessage(new AssistantMessage($content));
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    private function formatInterrupt(
        WorkflowInterrupt $interrupt,
        int $aiSessionId,
        string $userMessage,
        ?callable $emit,
        string $partialContent = '',
    ): array {
        $request = $interrupt->getRequest();
        if ($request instanceof FeedbackRequest) {
            if ($partialContent !== '') {
                $this->persistAssistantPartial($aiSessionId, $partialContent);
            }
            $feedback = $this->serializeFeedbackRequest($request, $interrupt->getWorkflowId());
            $content = $this->interruptContent(trim($partialContent), $this->feedbackHint($feedback));
            if ($emit) {
                $emit('feedback', $feedback);
            }

            return $this->payload($content, $userMessage, $aiSessionId, null, $feedback);
        }

        if (!$request instanceof ApprovalRequest) {
            throw new ChatException($interrupt->getMessage());
        }

        if ($partialContent !== '') {
            $this->persistAssistantPartial($aiSessionId, $partialContent);
        }

        $approval = $this->serializeApprovalRequest($request, $interrupt->getWorkflowId());
        $content = $this->interruptContent(trim($partialContent), $this->approvalHint($approval));
        if ($emit) {
            $emit('approval', $approval);
        }

        return $this->payload($content, $userMessage, $aiSessionId, $approval, null);
    }

    private function interruptContent(string $partialContent, string $hint): string
    {
        if ($partialContent === '') {
            return $hint;
        }

        if (str_contains($partialContent, '【待审核 · 请确认是否执行】')
            || str_contains($partialContent, '【待回答 · 请填写以下问题】')) {
            return $partialContent;
        }

        return trim($partialContent . "\n\n" . $hint);
    }

    private function assertAiSessionAccess(string $username, int $aiSessionId): void
    {
        $session = await($this->aiSessions->findById($aiSessionId));
        if ($session === null) {
            throw new ChatException('AI 会话不存在。', 404);
        }
        if (($session['username'] ?? '') !== $username) {
            throw new ChatException('无权访问该 AI 会话。', 403);
        }
        if (($session['status'] ?? '') !== 'active') {
            throw new ChatException('AI 会话已结束。', 409);
        }

        $this->execBridge->registerSession($aiSessionId, $username, $session['created_at'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApprovalRequest(ApprovalRequest $request, string $resumeToken): array
    {
        $actions = [];
        foreach ($request->getActions() as $action) {
            if (!$action->isPending()) {
                continue;
            }
            $label = $this->toolLabel($action->name);
            $rawDescription = (string) $action->description;
            $enriched = $this->enrichActionDescription($action->name, $rawDescription);
            $actions[] = [
                'id' => $action->id,
                'name' => $action->name,
                'label' => $label,
                'description' => $enriched['description'],
                'host' => $enriched['host'],
                'detail' => $this->actionDetail($label, $enriched['description']),
            ];
        }

        return [
            'resume_token' => $resumeToken,
            'message' => $request->getMessage(),
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFeedbackRequest(FeedbackRequest $request, string $resumeToken): array
    {
        $fields = [];
        foreach ($request->getFields() as $field) {
            $fields[] = $field->jsonSerialize();
        }

        return [
            'resume_token' => $resumeToken,
            'message' => $request->getMessage(),
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $approval
     */
    private function approvalHint(array $approval): string
    {
        $lines = ['【待审核 · 请确认是否执行】', ''];
        foreach ($approval['actions'] ?? [] as $action) {
            if (!is_array($action)) {
                continue;
            }
            $lines[] = '操作：' . (string) ($action['label'] ?? $action['name'] ?? '工具');
            $detail = trim((string) ($action['description'] ?? ''));
            if ($detail !== '') {
                $lines[] = $detail;
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $feedback
     */
    private function feedbackHint(array $feedback): string
    {
        $lines = ['【可选反馈 · 填写后提交，或点击跳过】', ''];
        foreach ($feedback['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $lines[] = '- ' . (string) ($field['label'] ?? $field['id'] ?? '问题');
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function buildFeedbackSummaryItems(FeedbackRequest $request): array
    {
        $items = [];
        foreach ($request->getFields() as $field) {
            $display = $this->formatFeedbackAnswer($field, $field->value);
            if ($display === null) {
                continue;
            }
            $items[] = ['label' => $field->label, 'value' => $display];
        }

        return $items;
    }

    private function formatFeedbackAnswer(FeedbackField $field, mixed $value): ?string
    {
        if ($field->type === 'checkbox') {
            if (!is_array($value) || $value === []) {
                return $field->required ? '—' : null;
            }

            return implode('、', array_map('strval', $value));
        }

        if (!is_string($value) || trim($value) === '') {
            return $field->required ? '—' : null;
        }

        return trim($value);
    }

    /**
     * @param list<array{label: string, value: string}> $summary
     */
    private function formatFeedbackSummaryText(array $summary): string
    {
        $blocks = [];
        foreach ($summary as $item) {
            $blocks[] = trim($item['label']);
            $blocks[] = trim($item['value']);
        }

        return trim(implode("\n", $blocks));
    }

    private function toolLabel(string $name): string
    {
        return match ($name) {
            OrchestratorRunSshCommandTool::NAME => '执行 SSH 命令',
            'ask_user' => '向用户提问',
            ListHostsTool::NAME => '列出主机',
            GetCommandContextTool::NAME => '读取命令输出',
            default => $name,
        };
    }

    private function actionDetail(string $label, string $description): string
    {
        $lines = ['操作：' . $label];
        if ($description !== '' && $description !== '(no arguments)') {
            $lines[] = $description;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{description: string, host: ?array{id: int, name: string, address: string, label: string}}
     */
    private function enrichActionDescription(string $name, string $description): array
    {
        $pretty = $this->prettyJson($description);
        if ($name !== OrchestratorRunSshCommandTool::NAME) {
            return ['description' => $pretty, 'host' => null];
        }

        $decoded = json_decode(trim($description), true);
        if (!is_array($decoded)) {
            return ['description' => $pretty, 'host' => null];
        }

        $hostId = (int) ($decoded['host_id'] ?? 0);
        $host = $hostId > 0 ? await($this->hosts->findById($hostId)) : null;
        $hostName = (string) ($host['name'] ?? '');
        $hostAddress = (string) ($host['address'] ?? '');
        $hostLabel = $this->formatHostLabel($hostName, $hostAddress, $hostId);

        $lines = [];
        if ($hostLabel !== '') {
            $lines[] = '目标主机：' . $hostLabel;
        }
        $reason = trim((string) ($decoded['reason'] ?? ''));
        if ($reason !== '') {
            $lines[] = '原因：' . $reason;
        }
        $command = trim((string) ($decoded['command'] ?? ''));
        if ($command !== '') {
            $lines[] = '命令：';
            $lines[] = $command;
        }

        $enriched = trim(implode("\n", $lines));

        return [
            'description' => $enriched !== '' ? $enriched : $pretty,
            'host' => $hostId > 0 ? [
                'id' => $hostId,
                'name' => $hostName,
                'address' => $hostAddress,
                'label' => $hostLabel,
            ] : null,
        ];
    }

    private function formatHostLabel(string $name, string $address, int $hostId): string
    {
        if ($name !== '' && $address !== '' && $name !== $address) {
            return $name . ' · ' . $address . ' (#' . $hostId . ')';
        }
        if ($name !== '') {
            return $name . ' (#' . $hostId . ')';
        }
        if ($address !== '') {
            return $address . ' (#' . $hostId . ')';
        }

        return $hostId > 0 ? ('主机 #' . $hostId) : '';
    }

    private function prettyJson(string $text): string
    {
        $text = trim($text);
        if ($text === '' || $text === '(no arguments)') {
            return $text;
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return $text;
        }
        $pretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return is_string($pretty) ? $pretty : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $content,
        string $userMessage,
        int $aiSessionId,
        ?array $approval,
        ?array $feedback,
    ): array {
        return [
            'content' => $content,
            'html' => $this->toHtml($content),
            'user_html' => $this->toHtml($userMessage),
            'thread_key' => $this->threadKey($aiSessionId),
            'ai_session_id' => $aiSessionId,
            'approval' => $approval,
            'feedback' => $feedback,
        ];
    }

    private function makeAgent(int $aiSessionId, string $username, ?HttpStreamScope $scope): OrchestratorAgent
    {
        OrchestratorToolContext::configure(
            $this->execBridge,
            $this->hosts,
            $aiSessionId,
            $username,
            $this->settings->commandTimeout(),
        );

        $http = $this->httpClient;
        if ($scope !== null && $http instanceof ReactHttpClient) {
            $http = $http->withHttpStreamScope($scope);
        }

        $agent = OrchestratorAgent::make($this->workflowPersistence(), $this->workflowResumeToken($aiSessionId));
        $agent->configure($this->settings, $http, $this->execBridge, $this->hosts, $aiSessionId, $username, true);
        $agent->toolMaxRuns($this->settings->toolMaxRuns());
        $agent->setChatHistory($this->fileHistory($aiSessionId));

        return $agent;
    }

    private function fileHistory(int $aiSessionId): ChatFileHistory
    {
        $createdAt = $this->sessionCreatedAt($aiSessionId);

        return new ChatFileHistory(
            directory: $this->settings->aiSessionStoragePaths()->chatDirectory($aiSessionId, $createdAt),
            key: (string) $aiSessionId,
            contextWindow: $this->settings->contextWindow(),
        );
    }

    private function sessionCreatedAt(int $aiSessionId): ?string
    {
        try {
            $session = await($this->aiSessions->findById($aiSessionId));

            return is_array($session) ? ($session['created_at'] ?? null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function workflowPersistence(): FilePersistence
    {
        $this->ensureDirectories();

        return new FilePersistence($this->settings->workflowPath());
    }

    private function workflowResumeToken(int $aiSessionId): string
    {
        return 'orchestrator-ai-' . sha1((string) $aiSessionId);
    }

    private function loadApprovalRequest(string $token): ?ApprovalRequest
    {
        try {
            $request = $this->workflowPersistence()->load($token)->getRequest();

            return $request instanceof ApprovalRequest ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function loadFeedbackRequest(string $token): ?FeedbackRequest
    {
        try {
            $request = $this->workflowPersistence()->load($token)->getRequest();

            return $request instanceof FeedbackRequest ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{role: string, content: string, html: string}>
     */
    private function loadMessages(int $aiSessionId): array
    {
        try {
            $history = $this->fileHistory($aiSessionId);
        } catch (Throwable) {
            return [];
        }

        $messages = [];
        foreach ($history->getMessages() as $item) {
            $role = method_exists($item, 'getRole') ? (string) $item->getRole() : '';
            if (!in_array($role, [MessageRole::USER->value, MessageRole::ASSISTANT->value], true)) {
                continue;
            }
            $content = method_exists($item, 'getContent') ? trim((string) $item->getContent()) : '';
            if ($content === '') {
                continue;
            }
            $content = ChatUtf8::sanitize($content);
            $messages[] = [
                'role' => $role,
                'content' => $content,
                'html' => $this->toHtml($content),
                'stopped' => $item instanceof AssistantMessage
                    && StoppedMessageMetadata::isStopped($item->getMetadata('stopped')),
            ];
        }

        return $messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadToolCalls(int $aiSessionId): array
    {
        try {
            $history = $this->fileHistory($aiSessionId);
        } catch (Throwable) {
            return [];
        }

        $calls = [];
        foreach ($history->getMessages() as $item) {
            if ($item instanceof ToolCallMessage) {
                foreach ($item->getTools() as $tool) {
                    $calls[] = $this->serializeToolCallEntry($tool->jsonSerialize(), 'running');
                }
                continue;
            }
            if (!$item instanceof ToolResultMessage) {
                continue;
            }
            foreach ($item->getTools() as $tool) {
                $payload = $tool->jsonSerialize();
                $callId = $payload['callId'] ?? null;
                $matched = false;
                for ($i = count($calls) - 1; $i >= 0; $i--) {
                    if ($callId !== null && ($calls[$i]['callId'] ?? null) === $callId) {
                        $calls[$i] = $this->mergeToolResult($calls[$i], $payload);
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $calls[] = $this->mergeToolResult(
                        $this->serializeToolCallEntry($payload, 'done'),
                        $payload,
                    );
                }
            }
        }

        return $calls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTimeline(int $aiSessionId): array
    {
        try {
            $history = $this->fileHistory($aiSessionId);
        } catch (Throwable) {
            return [];
        }

        return ChatTimelineBuilder::build(
            $history,
            fn (string $name): string => $this->toolLabel($name),
            fn (string $text): string => $this->toHtml($text),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function serializeToolCallEntry(array $payload, string $status): array
    {
        $name = (string) ($payload['name'] ?? '');
        $inputs = $payload['inputs'] ?? [];
        if ($inputs instanceof \stdClass) {
            $inputs = (array) $inputs;
        }

        return [
            'callId' => $payload['callId'] ?? null,
            'name' => $name,
            'label' => $this->toolLabel($name),
            'inputs' => is_array($inputs) ? $inputs : [],
            'result' => null,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mergeToolResult(array $entry, array $payload): array
    {
        $inputs = $payload['inputs'] ?? $entry['inputs'] ?? [];
        if ($inputs instanceof \stdClass) {
            $inputs = (array) $inputs;
        }
        if (is_array($inputs) && $inputs !== []) {
            $entry['inputs'] = $inputs;
        }

        $result = $payload['result'] ?? null;
        $entry['result'] = ChatUtf8::toolResult($result);
        $entry['status'] = 'done';

        return $entry;
    }

    private function toHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->settings->aiSessionStoragePath(), $this->settings->workflowPath()] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }
}
