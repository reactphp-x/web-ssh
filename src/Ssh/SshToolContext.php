<?php

declare(strict_types=1);

namespace App\Ssh;

use RuntimeException;

/**
 * Per-request tool context so Neuron tools stay serializable during workflow interrupts.
 */
final class SshToolContext
{
    private static ?SshSessionBridge $bridge = null;

    private static int $commandTimeout = 30;

    public static function configure(SshSessionBridge $bridge, int $commandTimeout): void
    {
        self::$bridge = $bridge;
        self::$commandTimeout = max(5, $commandTimeout);
    }

    public static function bridge(): SshSessionBridge
    {
        if (self::$bridge === null) {
            throw new RuntimeException('SshToolContext is not configured.');
        }

        return self::$bridge;
    }

    public static function commandTimeout(): int
    {
        return self::$commandTimeout;
    }
}
