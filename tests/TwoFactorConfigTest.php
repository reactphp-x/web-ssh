<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\TwoFactorConfig;
use PHPUnit\Framework\TestCase;
use ReactphpX\Framework\Environment;

final class TwoFactorConfigTest extends TestCase
{
    public function testDisabledWhenBasicAuthIsOff(): void
    {
        $env = Environment::load(dirname(__DIR__), '.env.example');

        self::assertFalse(TwoFactorConfig::load($env, false)->enabled());
    }

    public function testDefaultsToEnabledWhenBasicAuthIsOn(): void
    {
        $env = Environment::load(dirname(__DIR__), '.env.example');

        self::assertTrue(TwoFactorConfig::load($env, true)->enabled());
    }

    public function testCanBeDisabledViaEnv(): void
    {
        putenv('TWO_FACTOR_ENABLED=false');
        $_ENV['TWO_FACTOR_ENABLED'] = 'false';
        $_SERVER['TWO_FACTOR_ENABLED'] = 'false';

        $env = Environment::load(dirname(__DIR__), '.env.example');

        self::assertFalse(TwoFactorConfig::load($env, true)->enabled());

        putenv('TWO_FACTOR_ENABLED');
        unset($_ENV['TWO_FACTOR_ENABLED'], $_SERVER['TWO_FACTOR_ENABLED']);
    }
}
