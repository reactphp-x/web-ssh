<?php

declare(strict_types=1);

namespace App\Chat;

final class AiSettingsDefaults
{
    public const ENABLED = true;

    public const PROVIDER = 'openai';

    public const MODEL = 'gpt-4o-mini';

    public const HTTP_TIMEOUT = 120.0;

    public const COMMAND_TIMEOUT = 30;

    public const COMMAND_TIMEOUT_MAX = 300;

    public const TOOL_MAX_RUNS = 30;

    public const CONTEXT_WINDOW = 50000;

    public const SUMMARIZATION_ENABLED = true;

    public const SUMMARIZATION_KEEP = 5;

    public const ANTHROPIC_VERSION = '2023-06-01';

    public const ANTHROPIC_MAX_TOKENS = 8192;

    public const AZURE_API_VERSION = '2024-10-21';

    public const HF_INFERENCE_PROVIDER = 'hf-inference/models';

    public const AWS_REGION = 'us-east-1';

    public const OLLAMA_URL = 'http://localhost:11434/api';

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'enabled' => self::ENABLED,
            'provider' => self::PROVIDER,
            'model' => self::MODEL,
            'base_url' => '',
            'ollama_url' => self::OLLAMA_URL,
            'anthropic_version' => self::ANTHROPIC_VERSION,
            'anthropic_max_tokens' => self::ANTHROPIC_MAX_TOKENS,
            'azure_endpoint' => '',
            'azure_api_version' => self::AZURE_API_VERSION,
            'hf_inference_provider' => self::HF_INFERENCE_PROVIDER,
            'aws_region' => self::AWS_REGION,
            'vertex_credentials' => '',
            'vertex_project' => '',
            'vertex_location' => '',
            'http_timeout' => self::HTTP_TIMEOUT,
            'command_timeout' => self::COMMAND_TIMEOUT,
            'command_timeout_max' => self::COMMAND_TIMEOUT_MAX,
            'tool_max_runs' => self::TOOL_MAX_RUNS,
            'context_window' => self::CONTEXT_WINDOW,
            'summarization_enabled' => self::SUMMARIZATION_ENABLED,
            'summarization_max_tokens' => null,
            'summarization_keep' => self::SUMMARIZATION_KEEP,
        ];
    }
}
