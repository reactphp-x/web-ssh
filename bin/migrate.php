#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\DatabaseConfig;
use App\Database\DatabaseMigrator;
use App\Database\SqliteClientFactory;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\StreamSelectLoop;
use ReactphpX\Framework\Environment;

require __DIR__ . '/../vendor/autoload.php';

ReactLoop::set(new StreamSelectLoop());

$basePath = dirname(__DIR__);
$env = Environment::load($basePath);
$config = DatabaseConfig::load($env);
SqliteClientFactory::get($config);

$migrator = new DatabaseMigrator($config, $basePath . '/database/schema.sql');

$migrator->migrate()->then(
    static function (): void {
        echo "Database migrated successfully.\n";
        ReactLoop::stop();
    },
    static function (\Throwable $error): void {
        fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
        ReactLoop::stop();
        exit(1);
    },
);

ReactLoop::run();
