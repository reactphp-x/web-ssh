<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ssh\CommandTimeoutResolver;
use PHPUnit\Framework\TestCase;

final class CommandTimeoutResolverTest extends TestCase
{
    public function testUsesDefaultWhenRequestedIsNull(): void
    {
        self::assertSame(30, CommandTimeoutResolver::resolve(null, 30, 300));
    }

    public function testUsesDefaultWhenRequestedIsZeroOrNegative(): void
    {
        self::assertSame(30, CommandTimeoutResolver::resolve(0, 30, 300));
        self::assertSame(30, CommandTimeoutResolver::resolve(-1, 30, 300));
    }

    public function testClampsRequestedBelowMinimum(): void
    {
        self::assertSame(5, CommandTimeoutResolver::resolve(1, 30, 300));
    }

    public function testClampsRequestedAboveMax(): void
    {
        self::assertSame(300, CommandTimeoutResolver::resolve(600, 30, 300));
    }

    public function testAcceptsValidRequestedValue(): void
    {
        self::assertSame(120, CommandTimeoutResolver::resolve(120, 30, 300));
    }

    public function testMaxIsAtLeastDefaultWhenConfiguredLower(): void
    {
        self::assertSame(30, CommandTimeoutResolver::resolve(60, 30, 10));
    }
}
