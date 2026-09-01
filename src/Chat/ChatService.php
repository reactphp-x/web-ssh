<?php

declare(strict_types=1);

namespace App\Chat;

use App\Neuron\HttpClient\HttpStreamScope;
use App\Neuron\HttpClient\ReactHttpClient;
use App\Neuron\SshAgent;
use App\Neuron\Tools\RunSshCommandTool;
use App\Neuron\Workflow\FeedbackField;
use App\Neuron\Workflow\FeedbackRequest;
use App\Ssh\SshSessionBridge;
use App\Ssh\SshToolContext;
use NeuronAI\Chat\Enums\MessageRole;
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

final class ChatService
{
    public function __construct(
        private readonly ChatSettings $settings,
        private readonly SshSessionBridge $bridge,
        private readonly HttpClientInterface $httpClient,
        private readonly StreamChunkMapper $chunks,
        private readonly ChatStreamSession $streamSession,
        private readonly CommandApprovalTrust $approvalTrust,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function lockKey(string $username, string $connId): string
    {
        return sha1($username . ':' . $connId);
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(string $username, string $connId): array
    {
        $this->ensureDirectories();
        $lockKey = $this->lockKey($username, $connId);
        $this->repairIncompleteToolCalls($connId);
        $approval = $this->loadApproval($connId);
        $feedback = $this->loadFeedback($connId);
        $generation = $approval === null && $feedback === null
            ? $this->streamSession->getMeta($lockKey)
            : null;

        return [
            'configured' => $this->settings->isConfigured(),
            'enabled' => $this->settings->isEnabled(),
            'model' => $this->settings->model(),
            'thread_key' => $connId,
            'messages' => $this->loadMessages($connId),
            'tool_calls' => $this->loadToolCalls($connId),
            'timeline' => $this->loadTimeline($connId),
            'approval' => $approval,
            'feedback' => $feedback,
            'generation' => $generation,
            'command_auto_approve' => $this->approvalTrust->isEnabled($connId),
            'token_usage' => ChatTokenUsage::summarize($this->fileHistory($connId), $this->settings),
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): void $emit
     * @return array<string, mixed>
     */
    public function stream(
        string $username,
        string $connId,
        string $message,
        callable $emit,
        ?HttpStreamScope $scope = null,
    ): array {
        $message = trim($message);
        $emit('start', [
            'thread_key' => $connId,
            'user_html' => $this->toHtml($message),
        ]);

        return $this->invoke($username, $connId, $message, null, $emit, $scope);
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    public function resumeApproval(
        string $username,
        string $connId,
        bool $approved,
        ?string $feedback = null,
        ?callable $emit = null,
        ?HttpStreamScope $scope = null,
        bool $autoApproveSession = false,
    ): array {
        $token = $this->workflowResumeToken($connId);
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

        if ($approved && $autoApproveSession) {
            $this->approvalTrust->enable($connId);
        }

        $userLabel = $approved
            ? ($autoApproveSession ? '批准（本会话自动批准已开启）' : '批准')
            : '拒绝';
        if ($emit) {
            $emit('start', [
                'thread_key' => $connId,
                'user_html' => $this->toHtml($userLabel),
            ]);
        }

        return $this->invoke($username, $connId, $userLabel, $request, $emit, $scope);
    }

    /**
     * @param array<string, mixed> $answers
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    public function resumeFeedback(
        string $username,
        string $connId,
        array $answers,
        ?callable $emit = null,
        ?HttpStreamScope $scope = null,
    ): array {
        $token = $this->workflowResumeToken($connId);
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
                'thread_key' => $connId,
                'user_html' => $this->toHtml($userLabel),
            ]);
        }

        return $this->invoke($username, $connId, $userLabel, $request, $emit, $scope);
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    public function skipFeedback(
        string $username,
        string $connId,
        ?callable $emit = null,
        ?HttpStreamScope $scope = null,
    ): array {
        $token = $this->workflowResumeToken($connId);
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
                'thread_key' => $connId,
                'user_html' => $this->toHtml($userLabel),
            ]);
        }

        return $this->invoke($username, $connId, $userLabel, $request, $emit, $scope);
    }

    public function reset(string $connId): string
    {
        $this->approvalTrust->disable($connId);

        $directory = $this->settings->storagePath();
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*' . $connId . '*') ?: [] as $file) {
                @unlink($file);
            }
        }
        try {
            $this->workflowPersistence()->delete($this->workflowResumeToken($connId));
        } catch (Throwable) {
        }

        return $connId . '-' . time();
    }

    public function disableCommandAutoApprove(string $threadKey): void
    {
        $this->approvalTrust->disable($threadKey);
    }

    public function isCommandAutoApproveEnabled(string $threadKey): bool
    {
        return $this->approvalTrust->isEnabled($threadKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadApproval(string $connId): ?array
    {
        $request = $this->loadApprovalRequest($this->workflowResumeToken($connId));
        if ($request === null || $request->getPendingActions() === []) {
            return null;
        }

        return $this->serializeApprovalRequest($request, $this->workflowResumeToken($connId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadFeedback(string $connId): ?array
    {
        $request = $this->loadFeedbackRequest($this->workflowResumeToken($connId));
        if ($request === null || $request->getPendingFields() === []) {
            return null;
        }

        return $this->serializeFeedbackRequest($request, $this->workflowResumeToken($connId));
    }

    public function repairIncompleteToolCalls(string $connId): void
    {
        try {
            $this->fileHistory($connId)->repairIncompleteToolCalls(true);
        } catch (Throwable) {
        }
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    private function invoke(
        string $username,
        string $connId,
        string $userMessage,
        ?InterruptRequest $resume,
        ?callable $emit,
        ?HttpStreamScope $scope,
    ): array {
        $this->assertSessionAccess($username, $connId);

        $userMessage = trim($userMessage);
        if ($resume === null && $userMessage === '') {
            throw new ChatException('请输入要说的话');
        }

        if ($resume === null && $this->loadApproval($connId) !== null) {
            $pending = $this->loadApproval($connId);
            throw new ChatException('有待审核的命令，请先批准或拒绝。', data: [
                'approval' => $pending,
                'thread_key' => $connId,
            ]);
        }

        if ($resume === null && $this->loadFeedback($connId) !== null) {
            $pending = $this->loadFeedback($connId);
            throw new ChatException('有待回答的问题，可先提交、跳过反馈，或填写后提交。', data: [
                'feedback' => $pending,
                'thread_key' => $connId,
            ]);
        }

        if (!$this->settings->isEnabled()) {
            throw new ChatException('AI 助手未启用。');
        }
        if (!$this->settings->isConfigured()) {
            throw new ChatException('请先在侧栏「AI 设置」中配置 API Key。');
        }

        $this->ensureDirectories();
        $this->fileHistory($connId)->repairIncompleteToolCalls($resume === null);

        $agent = $this->makeAgent($connId, $scope);
        $assembled = '';
        $lockKey = $this->lockKey($username, $connId);

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
            $content = trim($assembled);

            return array_merge($this->payload($content, $userMessage, $connId, null, null), ['stopped' => true]);
        } catch (WorkflowInterrupt $interrupt) {
            return $this->formatInterrupt($interrupt, $connId, $userMessage, $emit, trim($assembled));
        } catch (Throwable $e) {
            $this->logger->error('ssh ai chat failed', [
                'conn_id' => $connId,
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

        return $this->payload($content, $userMessage, $connId, null, null);
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    private function formatInterrupt(
        WorkflowInterrupt $interrupt,
        string $connId,
        string $userMessage,
        ?callable $emit,
        string $partialContent = '',
    ): array {
        $request = $interrupt->getRequest();
        if ($request instanceof FeedbackRequest) {
            $feedback = $this->serializeFeedbackRequest($request, $interrupt->getWorkflowId());
            $content = $this->interruptContent(trim($partialContent), $this->feedbackHint($feedback));
            if ($emit) {
                $emit('feedback', $feedback);
            }

            return $this->payload($content, $userMessage, $connId, null, $feedback);
        }

        if (!$request instanceof ApprovalRequest) {
            throw new ChatException($interrupt->getMessage());
        }

        $approval = $this->serializeApprovalRequest($request, $interrupt->getWorkflowId());
        $content = $this->interruptContent(trim($partialContent), $this->approvalHint($approval));
        if ($emit) {
            $emit('approval', $approval);
        }

        return $this->payload($content, $userMessage, $connId, $approval, null);
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

    private function assertSessionAccess(string $username, string $connId): void
    {
        if (!$this->bridge->isConnected($connId)) {
            throw new ChatException('SSH 会话未连接，无法使用 AI 助手。');
        }
        if (!$this->bridge->isOwnedBy($connId, $username)) {
            throw new ChatException('无权访问该 SSH 会话。', 403);
        }
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
            $description = $this->prettyJson((string) $action->description);
            $actions[] = [
                'id' => $action->id,
                'name' => $action->name,
                'label' => $label,
                'description' => $description,
                'detail' => $this->actionDetail($label, $description),
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
            RunSshCommandTool::NAME => '执行 SSH 命令',
            'ask_user' => '向用户提问',
            'get_terminal_context' => '读取终端输出',
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
        string $connId,
        ?array $approval,
        ?array $feedback,
    ): array {
        return [
            'content' => $content,
            'html' => $this->toHtml($content),
            'user_html' => $this->toHtml($userMessage),
            'thread_key' => $connId,
            'approval' => $approval,
            'feedback' => $feedback,
            'token_usage' => ChatTokenUsage::summarize($this->fileHistory($connId), $this->settings),
        ];
    }

    private function makeAgent(string $connId, ?HttpStreamScope $scope): SshAgent
    {
        SshToolContext::configure(
            $this->bridge,
            $this->settings->commandTimeout(),
            $this->settings->commandTimeoutMax(),
            $connId,
            $this->approvalTrust,
        );

        $http = $this->httpClient;
        if ($scope !== null && $http instanceof ReactHttpClient) {
            $http = $http->withHttpStreamScope($scope);
        }

        $agent = SshAgent::make($this->workflowPersistence(), $this->workflowResumeToken($connId));
        $agent->configure($this->settings, $http, $this->bridge, $connId, true);
        $agent->toolMaxRuns($this->settings->toolMaxRuns());
        $agent->setChatHistory($this->fileHistory($connId));

        return $agent;
    }

    private function fileHistory(string $connId): ChatFileHistory
    {
        return new ChatFileHistory(
            directory: $this->settings->neuronChatStoragePaths()->connChatDirectory($connId),
            key: $connId,
            contextWindow: $this->settings->contextWindow(),
        );
    }

    private function workflowPersistence(): FilePersistence
    {
        $this->ensureDirectories();

        return new FilePersistence($this->settings->workflowPath());
    }

    private function workflowResumeToken(string $connId): string
    {
        return 'ssh-ai-' . sha1($connId);
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
    private function loadMessages(string $connId): array
    {
        try {
            $history = $this->fileHistory($connId);
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
            $messages[] = [
                'role' => $role,
                'content' => $content,
                'html' => $this->toHtml($content),
            ];
        }

        return $messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadToolCalls(string $connId): array
    {
        try {
            $history = $this->fileHistory($connId);
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
    private function loadTimeline(string $connId): array
    {
        try {
            $history = $this->fileHistory($connId);
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
        foreach ([$this->settings->storagePath(), $this->settings->workflowPath()] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }
}
