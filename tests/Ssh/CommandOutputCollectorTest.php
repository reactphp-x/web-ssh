<?php

declare(strict_types=1);

namespace App\Tests\Ssh;

use App\Ssh\CommandOutputCollector;
use PHPUnit\Framework\TestCase;

final class CommandOutputCollectorTest extends TestCase
{
    public function testStripAnsi(): void
    {
        $text = "\x1b[31merror\x1b[0m";
        self::assertSame('error', CommandOutputCollector::stripAnsi($text));
    }

    public function testSanitizeUtf8(): void
    {
        $text = "ok \xC0\x28 end";
        $sanitized = CommandOutputCollector::sanitizeUtf8($text);

        self::assertStringStartsWith('ok ', $sanitized);
        self::assertStringEndsWith(' end', $sanitized);
        self::assertStringNotContainsString("\xC0", $sanitized);
    }

    public function testNormalizeTerminalNewlines(): void
    {
        self::assertSame("\r\na\r\nb\r\n", CommandOutputCollector::normalizeTerminalNewlines("\na\nb\n"));
        self::assertSame("already\r\nok\r\n", CommandOutputCollector::normalizeTerminalNewlines("already\r\nok\n"));
    }
}
