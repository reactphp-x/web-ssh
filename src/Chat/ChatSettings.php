<?php

declare(strict_types=1);

namespace App\Chat;

use App\Storage\AiSessionStoragePaths;
use App\Storage\NeuronChatStoragePaths;
use ReactphpX\Framework\Environment;

final class ChatSettings
{
    public function __construct(private readonly Environment $environment)
    {
    }

    public function isEnabled(): bool
    {
        return filter_var($this->environment->string('AI_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        return trim($this->environment->string('NEURON_AI_KEY', $this->environment->string('OPENAI_KEY', '')));
    }

    public function model(): string
    {
        $model = trim($this->environment->string('NEURON_AI_MODEL', 'gpt-4o-mini'));

        return $model !== '' ? $model : 'gpt-4o-mini';
    }

    public function provider(): string
    {
        return strtolower(trim($this->environment->string('NEURON_AI_PROVIDER', 'openai')));
    }

    public function baseUri(): string
    {
        return rtrim(trim($this->environment->string('NEURON_AI_BASE_URL', '')), '/');
    }

    public function httpTimeout(): float
    {
        return $this->environment->float('NEURON_AI_HTTP_TIMEOUT', 120.0);
    }

    public function commandTimeout(): int
    {
        return max(5, $this->environment->int('AI_COMMAND_TIMEOUT', 30));
    }

    public function toolMaxRuns(): int
    {
        return max(1, $this->environment->int('NEURON_AI_TOOL_MAX_RUNS', 30));
    }

    public function storagePath(): string
    {
        return $this->environment->basePath() . '/storage/neuron';
    }

    public function workflowPath(): string
    {
        return $this->storagePath() . '/workflows';
    }

    public function aiSessionStoragePath(): string
    {
        return $this->storagePath() . '/ai-sessions';
    }

    public function aiSessionStoragePaths(): AiSessionStoragePaths
    {
        return new AiSessionStoragePaths($this->aiSessionStoragePath());
    }

    public function neuronChatStoragePaths(): NeuronChatStoragePaths
    {
        return new NeuronChatStoragePaths($this->storagePath());
    }

    public function contextWindow(): int
    {
        return max(1000, $this->environment->int('NEURON_CHAT_CONTEXT_WINDOW', 50000));
    }

    public function summarizationEnabled(): bool
    {
        return filter_var(
            $this->environment->string('NEURON_CHAT_SUMMARIZATION_ENABLED', 'true'),
            FILTER_VALIDATE_BOOL,
        );
    }

    public function summarizationMaxTokens(): int
    {
        $configured = trim($this->environment->string('NEURON_CHAT_SUMMARIZATION_MAX_TOKENS', ''));
        if ($configured !== '') {
            return max(0, (int) $configured);
        }

        return (int) floor($this->contextWindow() * 0.8);
    }

    public function summarizationMessagesToKeep(): int
    {
        return max(1, $this->environment->int('NEURON_CHAT_SUMMARIZATION_KEEP', 5));
    }

    public function summarizationPrompt(): string
    {
        return <<<'PROMPT'
            请用简洁中文总结以下对话，保留继续运维所需的关键信息，包括：
            - 讨论过的主机、路径、服务与配置
            - 已执行的重要命令及其结论
            - 用户目标、已做决策与待办事项
            - 尚未解决的问题或风险

            摘要应足够短以便放入上下文，但要保留必要细节，便于后续继续排查与操作。
            PROMPT;
    }
}
