<?php

declare(strict_types=1);

namespace App\Chat;

use App\Config\DatabaseConfig;
use App\Security\SecretCipher;
use SQLite3;

final class AiSettingsStore
{
    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, string> */
    private array $secrets = [];

    private bool $active = false;

    private ?int $profileId = null;

    private string $profileName = '';

    public function __construct(
        private readonly DatabaseConfig $dbConfig,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function loadSync(): void
    {
        $this->active = false;
        $this->config = [];
        $this->secrets = [];
        $this->profileId = null;
        $this->profileName = '';

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

            $result = $database->query(
                'SELECT id, name, config_json, encrypted_secrets, is_selected
                 FROM ai_profiles
                 WHERE is_selected = 1
                 ORDER BY id ASC
                 LIMIT 1',
            );
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $result->finalize();
            if (!is_array($row)) {
                return;
            }

            $this->profileId = (int) $row['id'];
            $this->profileName = (string) $row['name'];
            $this->applyRow($row);
        } finally {
            $database->close();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function applyRow(array $row): void
    {
        $this->active = (int) ($row['is_selected'] ?? 0) === 1;
        if (isset($row['id'])) {
            $this->profileId = (int) $row['id'];
        }
        if (isset($row['name'])) {
            $this->profileName = (string) $row['name'];
        }

        $decoded = json_decode((string) ($row['config_json'] ?? '{}'), true);
        $this->config = is_array($decoded) ? $decoded : [];

        $encrypted = trim((string) ($row['encrypted_secrets'] ?? ''));
        if ($encrypted === '') {
            $this->secrets = [];

            return;
        }

        $secretJson = $this->cipher->decrypt($encrypted);
        $decodedSecrets = json_decode($secretJson, true);
        $this->secrets = is_array($decodedSecrets) ? array_map('strval', $decodedSecrets) : [];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function profileId(): ?int
    {
        return $this->profileId;
    }

    public function profileName(): string
    {
        return $this->profileName;
    }

    public function getString(string $key): ?string
    {
        if (!array_key_exists($key, $this->config)) {
            return null;
        }

        $value = $this->config[$key];
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? '' : $string;
    }

    public function getBool(string $key): ?bool
    {
        if (!array_key_exists($key, $this->config)) {
            return null;
        }

        $value = $this->config[$key];
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    public function getInt(string $key): ?int
    {
        if (!array_key_exists($key, $this->config)) {
            return null;
        }

        $value = $this->config[$key];
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function getFloat(string $key): ?float
    {
        if (!array_key_exists($key, $this->config)) {
            return null;
        }

        $value = $this->config[$key];
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    public function getSecret(string $key): ?string
    {
        if (!array_key_exists($key, $this->secrets)) {
            return null;
        }

        return $this->secrets[$key];
    }

    public function hasSecret(string $key): bool
    {
        return trim((string) ($this->secrets[$key] ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, string>
     */
    public function secrets(): array
    {
        return $this->secrets;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $secrets
     */
    public function replace(array $config, array $secrets, bool $active = true, ?int $profileId = null, string $profileName = ''): void
    {
        $this->config = $config;
        $this->secrets = $secrets;
        $this->active = $active;
        if ($profileId !== null) {
            $this->profileId = $profileId;
        }
        if ($profileName !== '') {
            $this->profileName = $profileName;
        }
    }

    public function encodeSecrets(): string
    {
        if ($this->secrets === []) {
            return '';
        }

        $json = json_encode($this->secrets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $this->cipher->encrypt($json);
    }

    public function encodeConfig(): string
    {
        return json_encode($this->config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function reloadFromDatabase(): void
    {
        $this->loadSync();
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $secrets
     */
    public static function ephemeral(
        DatabaseConfig $dbConfig,
        SecretCipher $cipher,
        array $config,
        array $secrets,
    ): self {
        $store = new self($dbConfig, $cipher);
        $store->replace($config, $secrets, true);

        return $store;
    }
}
