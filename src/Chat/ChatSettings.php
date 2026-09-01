<?php

declare(strict_types=1);

namespace App\Chat;

use App\Storage\AiSessionStoragePaths;
use App\Storage\NeuronChatStoragePaths;
use ReactphpX\Framework\Environment;

final class ChatSettings
{
    public function __construct(
        private readonly Environment $environment,
        private readonly ?AiSettingsStore $store = null,
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->hasActiveProfile()) {
            return false;
        }

        $value = $this->store?->getBool('enabled');

        return $value ?? AiSettingsDefaults::ENABLED;
    }

    public function isConfigured(): bool
    {
        if (!$this->hasActiveProfile() || !$this->isEnabled()) {
            return false;
        }

        if ($this->provider() === 'ollama') {
            return true;
        }

        return $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        if (!$this->hasActiveProfile()) {
            return '';
        }

        return trim((string) ($this->store?->getSecret('api_key') ?? ''));
    }

    public function model(): string
    {
        $model = $this->profileString('model', AiSettingsDefaults::MODEL);

        return $model !== '' ? $model : AiSettingsDefaults::MODEL;
    }

    public function provider(): string
    {
        return strtolower($this->profileString('provider', AiSettingsDefaults::PROVIDER));
    }

    public function baseUri(): string
    {
        return rtrim($this->profileString('base_url', ''), '/');
    }

    public function anthropicVersion(): string
    {
        return $this->profileString('anthropic_version', AiSettingsDefaults::ANTHROPIC_VERSION);
    }

    public function anthropicMaxTokens(): int
    {
        return max(1, $this->profileInt('anthropic_max_tokens', AiSettingsDefaults::ANTHROPIC_MAX_TOKENS));
    }

    public function ollamaUrl(): string
    {
        $url = $this->profileString('ollama_url', '');

        return $url !== '' ? rtrim($url, '/') : ($this->baseUri() !== '' ? $this->baseUri() : AiSettingsDefaults::OLLAMA_URL);
    }

    public function azureEndpoint(): string
    {
        $endpoint = $this->profileString('azure_endpoint', '');

        return $endpoint !== '' ? $endpoint : $this->baseUri();
    }

    public function azureApiVersion(): string
    {
        return $this->profileString('azure_api_version', AiSettingsDefaults::AZURE_API_VERSION);
    }

    public function huggingFaceInferenceProvider(): string
    {
        return $this->profileString('hf_inference_provider', AiSettingsDefaults::HF_INFERENCE_PROVIDER);
    }

    public function awsRegion(): string
    {
        return $this->profileString('aws_region', AiSettingsDefaults::AWS_REGION);
    }

    public function awsAccessKey(): string
    {
        return $this->profileSecret('aws_access_key');
    }

    public function awsSecretKey(): string
    {
        return $this->profileSecret('aws_secret_key');
    }

    public function vertexCredentialsPath(): string
    {
        return $this->profileString('vertex_credentials', '');
    }

    public function vertexProjectId(): string
    {
        return $this->profileString('vertex_project', '');
    }

    public function vertexLocation(): ?string
    {
        $location = $this->profileString('vertex_location', '');

        return $location !== '' ? $location : null;
    }

    public function httpTimeout(): float
    {
        return $this->profileFloat('http_timeout', AiSettingsDefaults::HTTP_TIMEOUT);
    }

    public function commandTimeout(): int
    {
        return max(5, $this->profileInt('command_timeout', AiSettingsDefaults::COMMAND_TIMEOUT));
    }

    public function commandTimeoutMax(): int
    {
        return max($this->commandTimeout(), max(5, $this->profileInt(
            'command_timeout_max',
            AiSettingsDefaults::COMMAND_TIMEOUT_MAX,
        )));
    }

    public function toolMaxRuns(): int
    {
        return max(1, $this->profileInt('tool_max_runs', AiSettingsDefaults::TOOL_MAX_RUNS));
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
        return max(1000, $this->profileInt('context_window', AiSettingsDefaults::CONTEXT_WINDOW));
    }

    public function summarizationEnabled(): bool
    {
        if (!$this->hasActiveProfile()) {
            return AiSettingsDefaults::SUMMARIZATION_ENABLED;
        }

        $value = $this->store?->getBool('summarization_enabled');

        return $value ?? AiSettingsDefaults::SUMMARIZATION_ENABLED;
    }

    public function summarizationMaxTokens(): int
    {
        if ($this->hasActiveProfile()) {
            $configured = $this->store?->getInt('summarization_max_tokens');
            if ($configured !== null && $configured > 0) {
                return $configured;
            }
        }

        return (int) floor($this->contextWindow() * 0.8);
    }

    public function summarizationMessagesToKeep(): int
    {
        return max(1, $this->profileInt('summarization_keep', AiSettingsDefaults::SUMMARIZATION_KEEP));
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

    private function hasActiveProfile(): bool
    {
        return $this->store?->isActive() === true;
    }

    private function profileString(string $key, string $default): string
    {
        if (!$this->hasActiveProfile()) {
            return $default;
        }

        $value = $this->store?->getString($key);

        return $value !== null ? trim($value) : $default;
    }

    private function profileInt(string $key, int $default): int
    {
        if (!$this->hasActiveProfile()) {
            return $default;
        }

        $value = $this->store?->getInt($key);

        return $value ?? $default;
    }

    private function profileFloat(string $key, float $default): float
    {
        if (!$this->hasActiveProfile()) {
            return $default;
        }

        $value = $this->store?->getFloat($key);

        return $value ?? $default;
    }

    private function profileSecret(string $key): string
    {
        if (!$this->hasActiveProfile()) {
            return '';
        }

        return trim((string) ($this->store?->getSecret($key) ?? ''));
    }
}
