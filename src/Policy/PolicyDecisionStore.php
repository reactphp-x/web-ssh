<?php

declare(strict_types=1);

namespace App\Policy;

final class PolicyDecisionStore
{
    /** @var array<string, PolicyDecision> */
    private static array $decisions = [];

    /** @var array<string, list<string>> */
    private static array $keysByThread = [];

    public static function remember(string $threadKey, string $command, PolicyDecision $decision): void
    {
        if ($threadKey === '') {
            return;
        }

        $key = self::key($threadKey, $command);
        self::$decisions[$key] = $decision;
        self::$keysByThread[$threadKey] ??= [];
        if (!in_array($key, self::$keysByThread[$threadKey], true)) {
            self::$keysByThread[$threadKey][] = $key;
        }
    }

    public static function get(string $threadKey, string $command): ?PolicyDecision
    {
        if ($threadKey === '') {
            return null;
        }

        return self::$decisions[self::key($threadKey, $command)] ?? null;
    }

    public static function reset(): void
    {
        self::$decisions = [];
        self::$keysByThread = [];
    }

    public static function releaseThread(string $threadKey): void
    {
        if ($threadKey === '') {
            return;
        }

        foreach (self::$keysByThread[$threadKey] ?? [] as $key) {
            unset(self::$decisions[$key]);
        }

        unset(self::$keysByThread[$threadKey]);
    }

    private static function key(string $threadKey, string $command): string
    {
        return sha1($threadKey . "\0" . trim($command));
    }
}
