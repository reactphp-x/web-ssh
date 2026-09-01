<?php

declare(strict_types=1);

namespace App\Tests\Chat;

use App\Chat\CommandApprovalTrust;
use PHPUnit\Framework\TestCase;

final class CommandApprovalTrustTest extends TestCase
{
    protected function tearDown(): void
    {
        (new CommandApprovalTrust())->resetMemory();
    }

    public function testEnableDisableAndIsEnabled(): void
    {
        $trust = new CommandApprovalTrust();

        self::assertFalse($trust->isEnabled('conn-1'));

        $trust->enable('conn-1');
        self::assertTrue($trust->isEnabled('conn-1'));

        $trust->disable('conn-1');
        self::assertFalse($trust->isEnabled('conn-1'));
    }

    public function testThreadsAreIsolated(): void
    {
        $trust = new CommandApprovalTrust();

        $trust->enable('42');

        self::assertTrue($trust->isEnabled('42'));
        self::assertFalse($trust->isEnabled('43'));
    }

    public function testEmptyThreadKeyIsNeverEnabled(): void
    {
        $trust = new CommandApprovalTrust();

        $trust->enable('');

        self::assertFalse($trust->isEnabled(''));
    }
}
