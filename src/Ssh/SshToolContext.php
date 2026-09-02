<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Chat\CommandApprovalMode;
use App\Chat\CommandApprovalTrust;
use App\Policy\CommandPolicyEngine;
use App\Policy\PolicyDecisionStore;
use RuntimeException;

/**
 * Per-thread tool context so Neuron tools stay serializable during workflow interrupts.
 *
 * State is keyed by connId (threadKey) so concurrent AI streams in one worker do not overwrite each other.
 */
final class SshToolContext
{
    /** @var array<string, SshToolContextState> */
    private static array $states = [];

    private static ?string $activeThreadKey = null;

    public static function configure(
        SshSessionBridge $bridge,
        int $commandTimeout,
        int $commandTimeoutMax,
        string $threadKey,
        CommandApprovalTrust $approvalTrust,
        string $username = '',
        ?CommandPolicyEngine $policyEngine = null,
    ): void {
        if ($threadKey === '') {
            throw new RuntimeException('SshToolContext threadKey cannot be empty.');
        }

        PolicyDecisionStore::releaseThread($threadKey);

        $hostContext = $bridge->getHostContext($threadKey);
        $hostId = $hostContext['host_id'] ?? null;
        $hostGroupId = $hostContext['host_group_id'] ?? null;
        if (($hostContext['username'] ?? '') !== '') {
            $username = (string) $hostContext['username'];
        }

        self::$states[$threadKey] = new SshToolContextState(
            bridge: $bridge,
            policyEngine: $policyEngine,
            commandTimeout: max(5, $commandTimeout),
            commandTimeoutMax: max(max(5, $commandTimeout), max(5, $commandTimeoutMax)),
            threadKey: $threadKey,
            username: $username,
            hostId: is_int($hostId) ? $hostId : (is_numeric($hostId) ? (int) $hostId : null),
            hostGroupId: is_int($hostGroupId) ? $hostGroupId : (is_numeric($hostGroupId) ? (int) $hostGroupId : null),
            approvalTrust: $approvalTrust,
        );
        self::$activeThreadKey = $threadKey;
    }

    public static function use(string $threadKey): void
    {
        if ($threadKey === '' || !isset(self::$states[$threadKey])) {
            throw new RuntimeException(sprintf('SshToolContext is not configured for thread "%s".', $threadKey));
        }

        self::$activeThreadKey = $threadKey;
    }

    public static function release(string $threadKey): void
    {
        unset(self::$states[$threadKey]);
        PolicyDecisionStore::releaseThread($threadKey);
        if (self::$activeThreadKey === $threadKey) {
            self::$activeThreadKey = null;
        }
    }

    public static function bridge(): SshSessionBridge
    {
        return self::state()->bridge;
    }

    public static function policyEngine(): ?CommandPolicyEngine
    {
        return self::state()->policyEngine;
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

    public static function username(): string
    {
        return self::state()->username;
    }

    public static function hostId(): ?int
    {
        return self::state()->hostId;
    }

    public static function hostGroupId(): ?int
    {
        return self::state()->hostGroupId;
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

    private static function state(?string $threadKey = null): SshToolContextState
    {
        $key = $threadKey ?? self::$activeThreadKey;
        if ($key === null || !isset(self::$states[$key])) {
            throw new RuntimeException('SshToolContext is not configured.');
        }

        return self::$states[$key];
    }
}
