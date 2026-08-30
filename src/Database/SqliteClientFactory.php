<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\DatabaseConfig;
use RuntimeException;
use SQLite3;

final class SqliteClientFactory
{
    private static ?DatabaseClient $client = null;

    public static function get(DatabaseConfig $config): DatabaseClient
    {
        if (self::$client !== null) {
            return self::$client;
        }

        if (!extension_loaded('sqlite3')) {
            throw new RuntimeException('The sqlite3 PHP extension is required.');
        }

        $directory = dirname($config->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create SQLite storage directory.');
        }

        $database = new SQLite3($config->path);
        $database->enableExceptions(true);
        $database->exec('PRAGMA foreign_keys = ON');

        return self::$client = new DatabaseClient($database);
    }

    public static function reset(): void
    {
        self::$client = null;
    }
}
