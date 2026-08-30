<?php

declare(strict_types=1);

namespace App\Tests;

use App\Database\DatabaseClient;
use PHPUnit\Framework\TestCase;
use function React\Async\await;

final class DatabaseClientTest extends TestCase
{
    public function testPreparedInsertReturnsWriteResult(): void
    {
        $database = new \SQLite3(':memory:');
        $database->exec('CREATE TABLE sessions (token TEXT PRIMARY KEY, username TEXT NOT NULL)');

        $client = new DatabaseClient($database);
        $result = await($client->query('INSERT INTO sessions (token, username) VALUES (?, ?)', ['abc', 'admin']));

        self::assertSame(1, $result->affectedRows);
    }

    public function testPreparedSelectReturnsRows(): void
    {
        $database = new \SQLite3(':memory:');
        $database->exec('CREATE TABLE sessions (token TEXT PRIMARY KEY, username TEXT NOT NULL)');
        $database->exec("INSERT INTO sessions (token, username) VALUES ('abc', 'admin')");

        $client = new DatabaseClient($database);
        $result = await($client->query('SELECT token, username FROM sessions WHERE token = ?', ['abc']));

        self::assertSame('abc', $result->resultRows[0]['token'] ?? null);
    }
}
