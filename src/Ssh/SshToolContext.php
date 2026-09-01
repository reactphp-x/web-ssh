<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Chat\CommandApprovalTrust;
use RuntimeException;

/**
 * Per-request tool context so Neuron tools stay serializable during workflow interrupts.
 */
final class SshToolContext
{
    private static ?SshSessionBridge $bridge = null;

    private static int $commandTimeout = 30;

    private static int $commandTimeoutMax = 300;

    private static string $threadKey = '';

    private static ?CommandApprovalTrust $approvalTrust = null;

    public static function configure(
        SshSessionBridge $bridge,
        int $commandTimeout,
        int $commandTimeoutMax,
        string $threadKey,
        CommandApprovalTrust $approvalTrust,
    ): void {
        self::$bridge = $bridge;
        self::$commandTimeout = max(5, $commandTimeout);
        self::$commandTimeoutMax = max(self::$commandTimeout, max(5, $commandTimeoutMax));
        self::$threadKey = $threadKey;
        self::$approvalTrust = $approvalTrust;
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

    public static function commandTimeoutMax(): int
    {
        return self::$commandTimeoutMax;
    }

    public static function threadKey(): string
    {
        return self::$threadKey;
    }

    public static function commandApprovalRequired(): bool
    {
        if (self::$threadKey === '' || self::$approvalTrust === null) {
            return true;
        }

        return !self::$approvalTrust->isEnabled(self::$threadKey);
    }
}
