<?php

declare(strict_types=1);

namespace App\Ssh;

final class CommandTimeoutResolver
{
    public static function resolve(?int $requested, int $default, int $max): int
    {
        $default = max(5, $default);
        $max = max($default, $max);

        if ($requested === null || $requested <= 0) {
            return $default;
        }

        return min(max(5, $requested), $max);
    }
}
