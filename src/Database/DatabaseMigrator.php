<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\DatabaseConfig;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class DatabaseMigrator
{
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly string $schemaPath,
    ) {
    }

    public function migrate(): PromiseInterface
    {
        if (!is_readable($this->schemaPath)) {
            return resolve(null);
        }

        $statements = $this->parseStatements((string) file_get_contents($this->schemaPath));

        if (extension_loaded('sqlite3')) {
            $this->migrateSync($statements);

            return resolve(null);
        }

        $client = SqliteClientFactory::get($this->config);

        return array_reduce(
            $statements,
            static function (PromiseInterface $carry, string $statement) use ($client): PromiseInterface {
                return $carry->then(static fn () => $client->query($statement));
            },
            resolve(null),
        );
    }

    /**
     * @param list<string> $statements
     */
    private function migrateSync(array $statements): void
    {
        $directory = dirname($this->config->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $database = new \SQLite3($this->config->path);
        $database->enableExceptions(true);

        try {
            $database->exec('PRAGMA foreign_keys = ON');
            $this->upgradeHostsTable($database);
            $this->upgradeTwoFactorTables($database);
            $this->upgradeAiSessionTables($database);
            $this->upgradeAiProfilesTable($database);

            foreach ($statements as $statement) {
                $database->exec($statement);
            }
        } finally {
            $database->close();
        }
    }

    private function upgradeHostsTable(\SQLite3 $database): void
    {
        $hasHosts = $database->querySingle(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'hosts' LIMIT 1",
        );

        if ($hasHosts !== 1) {
            return;
        }

        $result = $database->query('PRAGMA table_info(hosts)');
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = (string) ($row['name'] ?? '');
        }

        if (!in_array('jump_host_id', $columns, true)) {
            $database->exec('ALTER TABLE hosts ADD COLUMN jump_host_id INTEGER NULL');
        }

        $database->exec('CREATE INDEX IF NOT EXISTS idx_hosts_jump_host_id ON hosts (jump_host_id)');
    }

    private function upgradeTwoFactorTables(\SQLite3 $database): void
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS two_factor_auth (
                username TEXT PRIMARY KEY,
                label TEXT NOT NULL,
                encrypted_secret TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $database->exec(
            'CREATE TABLE IF NOT EXISTS two_factor_pending (
                username TEXT PRIMARY KEY,
                label TEXT NOT NULL,
                encrypted_secret TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $database->exec(
            'CREATE TABLE IF NOT EXISTS two_factor_sessions (
                token TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $database->exec('CREATE INDEX IF NOT EXISTS idx_two_factor_sessions_username ON two_factor_sessions (username)');
        $database->exec('CREATE INDEX IF NOT EXISTS idx_two_factor_sessions_expires_at ON two_factor_sessions (expires_at)');
        $database->exec(
            'CREATE TABLE IF NOT EXISTS auth_rate_limits (
                bucket TEXT PRIMARY KEY,
                failures INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }

    private function upgradeAiSessionTables(\SQLite3 $database): void
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS ai_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL DEFAULT \'\',
                username TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'active\',
                active_segment_id INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at TEXT NULL
            )',
        );
        $database->exec('CREATE INDEX IF NOT EXISTS idx_ai_sessions_username ON ai_sessions (username)');
        $database->exec('CREATE INDEX IF NOT EXISTS idx_ai_sessions_status ON ai_sessions (status)');
        $database->exec('CREATE INDEX IF NOT EXISTS idx_ai_sessions_created_at ON ai_sessions (created_at)');

        $database->exec(
            'CREATE TABLE IF NOT EXISTS ai_session_segments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ai_session_id INTEGER NOT NULL,
                host_id INTEGER NOT NULL,
                session_id INTEGER NULL,
                live_key TEXT NOT NULL,
                order_index INTEGER NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at TEXT NULL,
                FOREIGN KEY (ai_session_id) REFERENCES ai_sessions (id) ON DELETE CASCADE,
                FOREIGN KEY (host_id) REFERENCES hosts (id) ON DELETE CASCADE,
                FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE SET NULL
            )',
        );
        $database->exec('CREATE INDEX IF NOT EXISTS idx_ai_session_segments_ai_session_id ON ai_session_segments (ai_session_id)');
        $database->exec('CREATE INDEX IF NOT EXISTS idx_ai_session_segments_host_id ON ai_session_segments (host_id)');

        $hasSessions = $database->querySingle(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sessions' LIMIT 1",
        );
        if ($hasSessions !== 1) {
            return;
        }

        $result = $database->query('PRAGMA table_info(sessions)');
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = (string) ($row['name'] ?? '');
        }

        if (!in_array('session_type', $columns, true)) {
            $database->exec("ALTER TABLE sessions ADD COLUMN session_type TEXT NOT NULL DEFAULT 'terminal'");
        }
        if (!in_array('ai_session_id', $columns, true)) {
            $database->exec('ALTER TABLE sessions ADD COLUMN ai_session_id INTEGER NULL');
        }

        $database->exec('CREATE INDEX IF NOT EXISTS idx_sessions_ai_session_id ON sessions (ai_session_id)');
        $database->exec('CREATE INDEX IF NOT EXISTS idx_sessions_session_type ON sessions (session_type)');
    }

    private function upgradeAiProfilesTable(\SQLite3 $database): void
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS ai_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                config_json TEXT NOT NULL DEFAULT \'{}\',
                encrypted_secrets TEXT NOT NULL DEFAULT \'\',
                is_selected INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by TEXT NOT NULL DEFAULT \'\',
                UNIQUE (name)
            )',
        );
        $database->exec('CREATE INDEX IF NOT EXISTS idx_ai_profiles_selected ON ai_profiles (is_selected)');

        $hasLegacy = $database->querySingle(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ai_settings' LIMIT 1",
        );
        if ($hasLegacy !== 1) {
            return;
        }

        $hasProfiles = (int) $database->querySingle('SELECT COUNT(*) FROM ai_profiles');
        if ($hasProfiles > 0) {
            return;
        }

        $result = $database->query('SELECT config_json, encrypted_secrets, active, updated_at, updated_by FROM ai_settings WHERE id = 1 LIMIT 1');
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        if (!is_array($row)) {
            return;
        }

        $stmt = $database->prepare(
            'INSERT INTO ai_profiles (name, config_json, encrypted_secrets, is_selected, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, '默认');
        $stmt->bindValue(2, (string) ($row['config_json'] ?? '{}'));
        $stmt->bindValue(3, (string) ($row['encrypted_secrets'] ?? ''));
        $stmt->bindValue(4, (int) ($row['active'] ?? 0) === 1 ? 1 : 0);
        $stmt->bindValue(5, (string) ($row['updated_at'] ?? date('c')));
        $stmt->bindValue(6, (string) ($row['updated_by'] ?? ''));
        $stmt->execute();
    }

    /**
     * @return list<string>
     */
    private function parseStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $statements[] = trim($buffer);
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}
