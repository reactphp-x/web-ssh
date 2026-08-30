<?php

declare(strict_types=1);

namespace App\Support;

use ReactX\Worker\Worker;

final class WorkerLog
{
    public static function info(string $message): void
    {
        Worker::log('[web-ssh] ' . $message);
    }

    public static function error(string $message): void
    {
        Worker::log('[web-ssh] ERROR ' . $message);
    }
}
