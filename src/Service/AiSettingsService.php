<?php

declare(strict_types=1);

namespace App\Service;

use App\Chat\AiSettingsDefaults;
use App\Chat\AiSettingsStore;
use App\Chat\ChatSettings;
use App\Config\DatabaseConfig;
use App\Neuron\AiModelLister;
use App\Neuron\AiProviderFactory;
use App\Neuron\HttpClient\ReactHttpClient;
use App\Repository\AiSettingsRepository;
use App\Security\SecretCipher;
use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use React\Promise\PromiseInterface;
use ReactphpX\Framework\Environment;
use Throwable;
use function React\Promise\resolve;

final class AiSettingsService
{
    /** @var list<string> */
    public const PROVIDERS = [
        'openai',
        'deepseek',
        'anthropic',
        'gemini',
        'ollama',
        'mistral',
        'cohere',
        'grok',
        'zai',
        'dashscope',
        'huggingface',
        'azure',
        'openai-responses',
        'bedrock',
        'gemini-vertex',
        'anthropic-vertex',
        'openailike',
        'openailike-responses',
    ];

    /** @var list<string> */
    private const CONFIG_FIELDS = [
        'enabled',
        'provider',
        'model',
        'base_url',
        'ollama_url',
        'anthropic_version',
        'anthropic_max_tokens',
        'azure_endpoint',
        'azure_api_version',
        'hf_inference_provider',
        'aws_region',
        'vertex_credentials',
        'vertex_project',
        'vertex_location',
        'http_timeout',
        'command_timeout',
        'command_timeout_max',
        'tool_max_runs',
        'context_window',
        'summarization_enabled',
        'summarization_max_tokens',
        'summarization_keep',
    ];

    /** @var list<string> */
    private const SECRET_FIELDS = [
        'api_key',
        'aws_access_key',
        'aws_secret_key',
    ];

    public function __construct(
        private readonly Environment $environment,
        private readonly DatabaseConfig $dbConfig,
        private readonly SecretCipher $cipher,
        private readonly AiSettingsStore $store,
        private readonly AiSettingsRepository $repository,
    ) {
    }

    public function get(): PromiseInterface
    {
        return $this->repository
            ->listAll()
            ->then(function (array $profiles): PromiseInterface {
                return $this->repository->findSelected()->then(
                    fn (?array $selected): array => $this->buildIndexPayload($profiles, $selected),
                );
            });
    }

    public function getProfile(int $id): PromiseInterface
    {
        return $this->repository
            ->findById($id)
            ->then(function (?array $row): array {
                if ($row === null) {
                    throw new InvalidArgumentException('AI 配置不存在。');
                }

                return $this->buildProfilePayload($row);
            });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, string $updatedBy): PromiseInterface
    {
        $name = $this->normalizeName($input);
        $config = $this->normalizeConfig($input);

        return $this->repository
            ->count()
            ->then(function (int $count) use ($name, $config, $input, $updatedBy): PromiseInterface {
                $secrets = $this->resolveSecretsForSave($input, null);
                $this->validateConfig($config, $secrets);

                $store = AiSettingsStore::ephemeral($this->dbConfig, $this->cipher, $config, $secrets);
                $select = $count === 0 || filter_var($input['select'] ?? true, FILTER_VALIDATE_BOOL);

                return $this->repository
                    ->create($name, $store, $updatedBy, $select)
                    ->then(function (int $id) use ($updatedBy): PromiseInterface {
                        $this->store->reloadFromDatabase();

                        return $this->get();
                    });
            });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, string $updatedBy): PromiseInterface
    {
        $name = $this->normalizeName($input);

        return $this->repository
            ->findById($id)
            ->then(function (?array $row) use ($id, $name, $input, $updatedBy): PromiseInterface {
                if ($row === null) {
                    throw new InvalidArgumentException('AI 配置不存在。');
                }

                $existingConfig = json_decode((string) ($row['config_json'] ?? '{}'), true);
                $existingConfig = is_array($existingConfig) ? $existingConfig : [];
                $config = $this->mergeConfig($existingConfig, $input);
                $secrets = $this->resolveSecretsForSave($input, $row);
                $this->validateConfig($config, $secrets);

                $store = AiSettingsStore::ephemeral($this->dbConfig, $this->cipher, $config, $secrets);

                return $this->repository
                    ->update($id, $name, $store, $updatedBy)
                    ->then(function () use ($id, $updatedBy): PromiseInterface {
                        if ($this->store->profileId() === $id) {
                            $this->store->reloadFromDatabase();
                        }

                        return $this->get();
                    });
            });
    }

    public function delete(int $id, string $updatedBy): PromiseInterface
    {
        return $this->repository
            ->findById($id)
            ->then(function (?array $row) use ($id, $updatedBy): PromiseInterface {
                if ($row === null) {
                    throw new InvalidArgumentException('AI 配置不存在。');
                }

                $wasSelected = (int) ($row['is_selected'] ?? 0) === 1;

                return $this->repository
                    ->delete($id)
                    ->then(function () use ($wasSelected, $updatedBy): PromiseInterface {
                        if ($wasSelected) {
                            $this->store->replace([], [], false);

                            return $this->repository
                                ->listAll()
                                ->then(function (array $profiles) use ($updatedBy): PromiseInterface {
                                    if ($profiles === []) {
                                        return resolve(null);
                                    }

                                    return $this->repository->select((int) $profiles[0]['id'], $updatedBy);
                                })
                                ->then(function () {
                                    $this->store->reloadFromDatabase();

                                    return $this->get();
                                });
                        }

                        return $this->get();
                    });
            });
    }

    public function select(int $id, string $updatedBy): PromiseInterface
    {
        return $this->repository
            ->findById($id)
            ->then(function (?array $row) use ($id, $updatedBy): PromiseInterface {
                if ($row === null) {
                    throw new InvalidArgumentException('AI 配置不存在。');
                }

                return $this->repository
                    ->select($id, $updatedBy)
                    ->then(function () {
                        $this->store->reloadFromDatabase();

                        return $this->get();
                    });
            });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function listModels(array $input): PromiseInterface
    {
        $profileId = isset($input['profile_id']) ? (int) $input['profile_id'] : null;

        $promise = $profileId !== null && $profileId > 0
            ? $this->repository->findById($profileId)
            : resolve(null);

        return $promise->then(function (?array $row) use ($input): array {
            try {
                $config = $this->normalizeConfig($input);
                $secrets = $this->resolveSecretsForSave($input, $row, forTest: true);
                $this->validateConfig($config, $secrets, requireKey: true);

                $tempStore = AiSettingsStore::ephemeral($this->dbConfig, $this->cipher, $config, $secrets);
                $tempSettings = new ChatSettings($this->environment, $tempStore);

                return (new AiModelLister())->list($tempSettings);
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'models' => [],
                    'manual_only' => true,
                    'message' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function test(array $input): PromiseInterface
    {
        $profileId = isset($input['profile_id']) ? (int) $input['profile_id'] : null;

        $promise = $profileId !== null && $profileId > 0
            ? $this->repository->findById($profileId)
            : resolve(null);

        return $promise->then(function (?array $row) use ($input): array {
            try {
                $config = $this->normalizeConfig($input);
                $secrets = $this->resolveSecretsForSave($input, $row, forTest: true);
                $this->validateConfig($config, $secrets, requireKey: true);

                $tempStore = AiSettingsStore::ephemeral($this->dbConfig, $this->cipher, $config, $secrets);
                $tempSettings = new ChatSettings($this->environment, $tempStore);
                $provider = AiProviderFactory::create(
                    $tempSettings,
                    new ReactHttpClient(timeout: $tempSettings->httpTimeout()),
                );
                $response = $provider->chat(new UserMessage('ping'));
                $content = trim((string) $response->getContent());

                return [
                    'success' => true,
                    'message' => $content !== '' ? '连接成功，模型已响应。' : '连接成功。',
                    'sample' => mb_substr($content, 0, 200),
                ];
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $profiles
     *
     * @return array<string, mixed>
     */
    private function buildIndexPayload(array $profiles, ?array $selected): array
    {
        $dbConfig = [];
        $dbSecrets = [];

        if ($selected !== null) {
            $decoded = json_decode((string) ($selected['config_json'] ?? '{}'), true);
            $dbConfig = is_array($decoded) ? $decoded : [];
            $dbSecrets = $this->decodeSecrets($selected);
        }

        $effective = $selected !== null
            ? $this->effectiveSettings($dbConfig, $dbSecrets)
            : AiSettingsDefaults::config();

        if ($selected === null) {
            $effective['api_key_set'] = false;
            $effective['aws_access_key_set'] = false;
            $effective['aws_secret_key_set'] = false;
        }

        return [
            'configured' => $selected !== null && ($effective['api_key_set'] || ($effective['provider'] ?? '') === 'ollama'),
            'selected_profile_id' => $selected !== null ? (int) $selected['id'] : null,
            'profiles' => $profiles,
            'providers' => self::PROVIDERS,
            'settings' => $effective,
            'secrets' => [
                'api_key_set' => (bool) ($effective['api_key_set'] ?? false),
                'aws_access_key_set' => (bool) ($effective['aws_access_key_set'] ?? false),
                'aws_secret_key_set' => (bool) ($effective['aws_secret_key_set'] ?? false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfilePayload(array $row): array
    {
        $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        $secrets = $this->decodeSecrets($row);
        $effective = $this->effectiveSettings($config, $secrets);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'is_selected' => (int) ($row['is_selected'] ?? 0) === 1,
            'settings' => $effective,
            'secrets' => [
                'api_key_set' => trim((string) ($secrets['api_key'] ?? '')) !== '',
                'aws_access_key_set' => trim((string) ($secrets['aws_access_key'] ?? '')) !== '',
                'aws_secret_key_set' => trim((string) ($secrets['aws_secret_key'] ?? '')) !== '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function decodeSecrets(array $row): array
    {
        $encrypted = trim((string) ($row['encrypted_secrets'] ?? ''));
        if ($encrypted === '') {
            return [];
        }

        $secretJson = $this->cipher->decrypt($encrypted);
        $decodedSecrets = json_decode($secretJson, true);

        return is_array($decodedSecrets) ? array_map('strval', $decodedSecrets) : [];
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @param array<string, string> $dbSecrets
     *
     * @return array<string, mixed>
     */
    private function effectiveSettings(array $dbConfig, array $dbSecrets): array
    {
        $merged = AiSettingsDefaults::config();

        foreach (self::CONFIG_FIELDS as $field) {
            if (array_key_exists($field, $dbConfig) && $dbConfig[$field] !== null) {
                $merged[$field] = $dbConfig[$field];
            }
        }

        $merged['api_key_set'] = trim((string) ($dbSecrets['api_key'] ?? '')) !== '';
        $merged['aws_access_key_set'] = trim((string) ($dbSecrets['aws_access_key'] ?? '')) !== '';
        $merged['aws_secret_key_set'] = trim((string) ($dbSecrets['aws_secret_key'] ?? '')) !== '';

        return $merged;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function normalizeName(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('请填写配置名称。');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $input): array
    {
        $config = [];
        foreach (self::CONFIG_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];
            if ($field === 'enabled' || $field === 'summarization_enabled') {
                $config[$field] = filter_var($value, FILTER_VALIDATE_BOOL);
                continue;
            }

            if (in_array($field, ['anthropic_max_tokens', 'command_timeout', 'command_timeout_max', 'tool_max_runs', 'context_window', 'summarization_max_tokens', 'summarization_keep'], true)) {
                $config[$field] = $value === '' || $value === null ? null : (int) $value;
                continue;
            }

            if ($field === 'http_timeout') {
                $config[$field] = $value === '' || $value === null ? null : (float) $value;
                continue;
            }

            $config[$field] = trim((string) $value);
        }

        if (isset($config['provider'])) {
            $config['provider'] = strtolower(trim((string) $config['provider']));
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function mergeConfig(array $existing, array $input): array
    {
        $merged = $existing;
        foreach ($this->normalizeConfig($input) as $field => $value) {
            $merged[$field] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existingRow
     *
     * @return array<string, string>
     */
    private function resolveSecretsForSave(array $input, ?array $existingRow, bool $forTest = false): array
    {
        $secrets = $existingRow !== null ? $this->decodeSecrets($existingRow) : [];

        foreach (self::SECRET_FIELDS as $field) {
            $keep = filter_var($input['keep_' . $field] ?? true, FILTER_VALIDATE_BOOL);
            $incoming = trim((string) ($input[$field] ?? ''));
            if ($incoming !== '') {
                $secrets[$field] = $incoming;
                continue;
            }

            if ($keep && isset($secrets[$field])) {
                continue;
            }

            if (!$keep) {
                unset($secrets[$field]);
            }
        }

        return $secrets;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $secrets
     */
    private function validateConfig(array $config, array $secrets, bool $requireKey = false): void
    {
        $provider = strtolower(trim((string) ($config['provider'] ?? AiSettingsDefaults::PROVIDER)));
        $baseUrl = trim((string) ($config['base_url'] ?? ''));

        if ($requireKey || $provider !== 'ollama') {
            if (trim((string) ($secrets['api_key'] ?? '')) === '') {
                throw new InvalidArgumentException('请填写 API Key。');
            }
        }

        if (in_array($provider, ['openailike', 'openailike-responses'], true) && $baseUrl === '') {
            throw new InvalidArgumentException('OpenAI 兼容接口需要填写 Base URL。');
        }

        if ($provider === 'azure' && trim((string) ($config['azure_endpoint'] ?? '')) === '') {
            throw new InvalidArgumentException('Azure OpenAI 需要填写 Endpoint。');
        }

        if (in_array($provider, ['gemini-vertex', 'anthropic-vertex'], true)) {
            if (trim((string) ($config['vertex_credentials'] ?? '')) === '' || trim((string) ($config['vertex_project'] ?? '')) === '') {
                throw new InvalidArgumentException('Vertex AI 需要填写凭据路径与 Project ID。');
            }
        }

        $commandTimeout = max(5, (int) ($config['command_timeout'] ?? AiSettingsDefaults::COMMAND_TIMEOUT));
        $commandTimeoutMax = max(5, (int) ($config['command_timeout_max'] ?? AiSettingsDefaults::COMMAND_TIMEOUT_MAX));
        if ($commandTimeoutMax < $commandTimeout) {
            throw new InvalidArgumentException('命令超时上限不能小于默认命令超时。');
        }
    }
}
