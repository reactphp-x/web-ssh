<?php

declare(strict_types=1);

namespace App\Chat;

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

    public function contextWindow(): int
    {
        return max(1000, $this->environment->int('NEURON_CHAT_CONTEXT_WINDOW', 50000));
    }
}
