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
