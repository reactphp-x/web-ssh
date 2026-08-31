<?php

declare(strict_types=1);

namespace App\Chat;

use App\Config\DatabaseConfig;
use App\Security\SecretCipher;
use ReactphpX\Framework\Environment;
use SQLite3;

final class AiSettingsEnvImporter
{
    public function __construct(
        private readonly Environment $environment,
        private readonly DatabaseConfig $dbConfig,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function importIfEmpty(): void
    {
        if (!extension_loaded('sqlite3') || !is_readable($this->dbConfig->path)) {
            return;
        }

        $database = new SQLite3($this->dbConfig->path);
        $database->enableExceptions(true);

        try {
            $hasTable = $database->querySingle(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ai_profiles' LIMIT 1",
            );
            if ($hasTable !== 1) {
                return;
            }

            $count = (int) $database->querySingle('SELECT COUNT(*) FROM ai_profiles');
            if ($count > 0) {
                return;
            }

            $apiKey = trim($this->environment->string('NEURON_AI_KEY', $this->environment->string('OPENAI_KEY', '')));
            if ($apiKey === '') {
                return;
            }

            $config = [
                'enabled' => filter_var($this->environment->string('AI_ENABLED', 'true'), FILTER_VALIDATE_BOOL),
                'provider' => strtolower(trim($this->environment->string('NEURON_AI_PROVIDER', 'openai'))),
                'model' => trim($this->environment->string('NEURON_AI_MODEL', 'gpt-4o-mini')),
                'base_url' => rtrim(trim($this->environment->string('NEURON_AI_BASE_URL', '')), '/'),
                'ollama_url' => trim($this->environment->string('NEURON_AI_OLLAMA_URL', '')),
                'anthropic_version' => trim($this->environment->string('NEURON_AI_ANTHROPIC_VERSION', '2023-06-01')),
                'anthropic_max_tokens' => $this->environment->int('NEURON_AI_ANTHROPIC_MAX_TOKENS', 8192),
                'azure_endpoint' => trim($this->environment->string('NEURON_AI_AZURE_ENDPOINT', '')),
                'azure_api_version' => trim($this->environment->string('NEURON_AI_AZURE_API_VERSION', '2024-10-21')),
                'hf_inference_provider' => trim($this->environment->string('NEURON_AI_HF_INFERENCE_PROVIDER', 'hf-inference/models')),
                'aws_region' => trim($this->environment->string('NEURON_AI_AWS_REGION', 'us-east-1')),
                'vertex_credentials' => trim($this->environment->string('NEURON_AI_VERTEX_CREDENTIALS', '')),
                'vertex_project' => trim($this->environment->string('NEURON_AI_VERTEX_PROJECT', '')),
                'vertex_location' => trim($this->environment->string('NEURON_AI_VERTEX_LOCATION', '')),
                'http_timeout' => $this->environment->float('NEURON_AI_HTTP_TIMEOUT', 120.0),
                'command_timeout' => $this->environment->int('AI_COMMAND_TIMEOUT', 30),
                'tool_max_runs' => $this->environment->int('NEURON_AI_TOOL_MAX_RUNS', 30),
                'context_window' => $this->environment->int('NEURON_CHAT_CONTEXT_WINDOW', 50000),
                'summarization_enabled' => filter_var(
                    $this->environment->string('NEURON_CHAT_SUMMARIZATION_ENABLED', 'true'),
                    FILTER_VALIDATE_BOOL,
                ),
                'summarization_max_tokens' => $this->nullableInt('NEURON_CHAT_SUMMARIZATION_MAX_TOKENS'),
                'summarization_keep' => $this->environment->int('NEURON_CHAT_SUMMARIZATION_KEEP', 5),
            ];

            $secrets = ['api_key' => $apiKey];
            $awsKey = trim($this->environment->string('NEURON_AI_AWS_ACCESS_KEY', ''));
            $awsSecret = trim($this->environment->string('NEURON_AI_AWS_SECRET_KEY', ''));
            if ($awsKey !== '') {
                $secrets['aws_access_key'] = $awsKey;
            }
            if ($awsSecret !== '') {
                $secrets['aws_secret_key'] = $awsSecret;
            }

            $store = AiSettingsStore::ephemeral($this->dbConfig, $this->cipher, $config, $secrets);
            $stmt = $database->prepare(
                'INSERT INTO ai_profiles (name, config_json, encrypted_secrets, is_selected, updated_at, updated_by)
                 VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP, ?)',
            );
            $stmt->bindValue(1, '默认');
            $stmt->bindValue(2, $store->encodeConfig());
            $stmt->bindValue(3, $store->encodeSecrets());
            $stmt->bindValue(4, 'system');
            $stmt->execute();
        } catch (\Throwable) {
            // Best-effort one-time migration from legacy .env values.
        } finally {
            $database->close();
        }
    }

    private function nullableInt(string $key): ?int
    {
        $value = trim($this->environment->string($key, ''));

        return $value === '' ? null : (int) $value;
    }
}
