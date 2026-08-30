<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\DatabaseConfig;
use App\Database\DatabaseMigrator;
use App\Database\SqliteClientFactory;
use App\Support\WorkerLog;

final class DatabaseBootstrap
{
    public static function start(DatabaseConfig $config, string $schemaPath): void
    {
        SqliteClientFactory::get($config);

        if (!$config->autoMigrate) {
            return;
        }

        $migrator = new DatabaseMigrator($config, $schemaPath);
        $migrator->migrate()->then(
            static function (): void {
                WorkerLog::info('Database schema migrated.');
            },
            static function (\Throwable $error): void {
                WorkerLog::error('Database migration failed: ' . $error->getMessage());
            },
        );
    }
}
