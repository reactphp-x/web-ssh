<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\SessionRenewal;
use PHPUnit\Framework\TestCase;

final class SessionRenewalTest extends TestCase
{
    public function testShouldRenewWhenRemainingBelowThreshold(): void
    {
        $ttl = 14400;
        $renewInterval = 1800;
        $expiresAt = time() + 1000;

        self::assertTrue(SessionRenewal::shouldRenew($expiresAt, $ttl, $renewInterval));
    }

    public function testShouldNotRenewWhenRemainingAboveThreshold(): void
    {
        $ttl = 14400;
        $renewInterval = 1800;
        $expiresAt = time() + 13000;

        self::assertFalse(SessionRenewal::shouldRenew($expiresAt, $ttl, $renewInterval));
    }

    public function testShouldRenewAtExactThreshold(): void
    {
        $ttl = 3600;
        $renewInterval = 1800;
        $expiresAt = time() + 1799;

        self::assertTrue(SessionRenewal::shouldRenew($expiresAt, $ttl, $renewInterval));
    }
}
