<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Chat\CommandApprovalTrust;
use App\Repository\HostRepository;
use RuntimeException;

final class OrchestratorToolContext
{
    private static ?SshExecBridge $execBridge = null;

    private static ?HostRepository $hosts = null;

    private static int $aiSessionId = 0;

    private static string $username = '';

    private static int $commandTimeout = 30;

    private static int $commandTimeoutMax = 300;

    private static string $threadKey = '';

    private static ?CommandApprovalTrust $approvalTrust = null;

    public static function configure(
        SshExecBridge $execBridge,
        HostRepository $hosts,
        int $aiSessionId,
        string $username,
        int $commandTimeout,
        int $commandTimeoutMax,
        CommandApprovalTrust $approvalTrust,
    ): void {
        self::$execBridge = $execBridge;
        self::$hosts = $hosts;
        self::$aiSessionId = $aiSessionId;
        self::$username = $username;
        self::$commandTimeout = max(5, $commandTimeout);
        self::$commandTimeoutMax = max(self::$commandTimeout, max(5, $commandTimeoutMax));
        self::$threadKey = (string) $aiSessionId;
        self::$approvalTrust = $approvalTrust;
    }

    public static function execBridge(): SshExecBridge
    {
        if (self::$execBridge === null) {
            throw new RuntimeException('OrchestratorToolContext is not configured.');
        }

        return self::$execBridge;
    }

    public static function hosts(): HostRepository
    {
        if (self::$hosts === null) {
            throw new RuntimeException('OrchestratorToolContext is not configured.');
        }

        return self::$hosts;
    }

    public static function aiSessionId(): int
    {
        return self::$aiSessionId;
    }

    public static function username(): string
    {
        return self::$username;
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
