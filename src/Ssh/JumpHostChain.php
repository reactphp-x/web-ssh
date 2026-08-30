<?php

declare(strict_types=1);

namespace App\Ssh;

final class JumpHostChain
{
    public const MAX_DEPTH = 5;

    /**
     * @param array<int, int|null> $jumpByHostId host id => jump_host_id
     */
    public static function wouldCycle(array $jumpByHostId, int $hostId, int $jumpHostId): bool
    {
        if ($jumpHostId <= 0) {
            return false;
        }

        if ($hostId > 0 && $hostId === $jumpHostId) {
            return true;
        }

        $seen = [];
        if ($hostId > 0) {
            $seen[$hostId] = true;
        }

        $current = $jumpHostId;
        $depth = 0;
        while ($current !== null && $current > 0) {
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $depth++;
            if ($depth > self::MAX_DEPTH) {
                return true;
            }

            $next = $jumpByHostId[$current] ?? null;
            $current = $next !== null && (int) $next > 0 ? (int) $next : null;
        }

        return false;
    }

    /**
     * Outermost jump first, immediate jump last.
     *
     * @return list<SshTarget>
     */
    public static function hopsFromTarget(SshTarget $target): array
    {
        $hops = [];
        $jump = $target->jump;
        while ($jump !== null) {
            $hops[] = $jump;
            $jump = $jump->jump;
        }

        return array_reverse($hops);
    }

    public static function outermost(SshTarget $target): SshTarget
    {
        $current = $target;
        while ($current->jump !== null) {
            $current = $current->jump;
        }

        return $current;
    }
}
