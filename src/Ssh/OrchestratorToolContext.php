<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Chat\CommandApprovalMode;
use App\Chat\CommandApprovalTrust;
use App\Policy\CommandPolicyEngine;
use App\Policy\PolicyDecisionStore;
use App\Repository\HostRepository;
use RuntimeException;

/**
 * Per-thread orchestrator tool context (keyed by aiSessionId).
 */
final class OrchestratorToolContext
{
    /** @var array<string, OrchestratorToolContextState> */
    private static array $states = [];

    private static ?string $activeThreadKey = null;

    public static function configure(
        SshExecBridge $execBridge,
        HostRepository $hosts,
        int $aiSessionId,
        string $username,
        int $commandTimeout,
        int $commandTimeoutMax,
        CommandApprovalTrust $approvalTrust,
        ?CommandPolicyEngine $policyEngine = null,
    ): void {
        $threadKey = (string) $aiSessionId;
        if ($threadKey === '' || $threadKey === '0') {
            throw new RuntimeException('OrchestratorToolContext aiSessionId is invalid.');
        }

        PolicyDecisionStore::releaseThread($threadKey);

        self::$states[$threadKey] = new OrchestratorToolContextState(
            execBridge: $execBridge,
            hosts: $hosts,
            policyEngine: $policyEngine,
            aiSessionId: $aiSessionId,
            username: $username,
            commandTimeout: max(5, $commandTimeout),
            commandTimeoutMax: max(max(5, $commandTimeout), max(5, $commandTimeoutMax)),
            threadKey: $threadKey,
            approvalTrust: $approvalTrust,
        );
        self::$activeThreadKey = $threadKey;
    }

    public static function use(string $threadKey): void
    {
        if ($threadKey === '' || !isset(self::$states[$threadKey])) {
            throw new RuntimeException(sprintf('OrchestratorToolContext is not configured for thread "%s".', $threadKey));
        }

        self::$activeThreadKey = $threadKey;
    }

    public static function useSession(int $aiSessionId): void
    {
        self::use((string) $aiSessionId);
    }

    public static function release(string $threadKey): void
    {
        unset(self::$states[$threadKey]);
        PolicyDecisionStore::releaseThread($threadKey);
        if (self::$activeThreadKey === $threadKey) {
            self::$activeThreadKey = null;
        }
    }

    public static function releaseSession(int $aiSessionId): void
    {
        self::release((string) $aiSessionId);
    }

    public static function execBridge(): SshExecBridge
    {
        return self::state()->execBridge;
    }

    public static function hosts(): HostRepository
    {
        return self::state()->hosts;
    }

    public static function policyEngine(): ?CommandPolicyEngine
    {
        return self::state()->policyEngine;
    }

    public static function aiSessionId(): int
    {
        return self::state()->aiSessionId;
    }

    public static function username(): string
    {
        return self::state()->username;
    }

    public static function commandTimeout(): int
    {
        return self::state()->commandTimeout;
    }

    public static function commandTimeoutMax(): int
    {
        return self::state()->commandTimeoutMax;
    }

    public static function threadKey(): string
    {
        return self::state()->threadKey;
    }

    public static function sessionTrustEnabled(?string $threadKey = null): bool
    {
        return self::approvalMode($threadKey) !== CommandApprovalMode::AlwaysApprove;
    }

    public static function approvalMode(?string $threadKey = null): CommandApprovalMode
    {
        $state = self::state($threadKey);

        return $state->approvalTrust->getMode($state->threadKey);
    }

    private static function state(?string $threadKey = null): OrchestratorToolContextState
    {
        $key = $threadKey ?? self::$activeThreadKey;
        if ($key === null || !isset(self::$states[$key])) {
            throw new RuntimeException('OrchestratorToolContext is not configured.');
        }

        return self::$states[$key];
    }
}
