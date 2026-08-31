<?php

declare(strict_types=1);

namespace App\Chat;

final class StoppedMessageMetadata
{
    public const STOPPED = '1';

    public static function isStopped(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 'true';
    }
}
