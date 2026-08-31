<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Repository\HostRepository;
use RuntimeException;

final class OrchestratorToolContext
{
    private static ?SshExecBridge $execBridge = null;

    private static ?HostRepository $hosts = null;

    private static int $aiSessionId = 0;

    private static string $username = '';

    private static int $commandTimeout = 30;

    public static function configure(
        SshExecBridge $execBridge,
        HostRepository $hosts,
        int $aiSessionId,
        string $username,
        int $commandTimeout,
    ): void {
        self::$execBridge = $execBridge;
        self::$hosts = $hosts;
        self::$aiSessionId = $aiSessionId;
        self::$username = $username;
        self::$commandTimeout = max(5, $commandTimeout);
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
}
