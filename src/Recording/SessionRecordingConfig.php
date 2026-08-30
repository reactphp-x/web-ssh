<?php

declare(strict_types=1);

namespace App\Recording;

use ReactphpX\Framework\Environment;

final class SessionRecordingConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $storageDir,
        public readonly int $partMaxBytes,
    ) {
    }

    public static function load(Environment $env): self
    {
        $enabled = filter_var(
            $env->nullableString('SESSION_RECORDING_ENABLED') ?? 'true',
            FILTER_VALIDATE_BOOL,
        );

        $dir = trim($env->string('SESSION_RECORDING_DIR', 'storage/recordings'));
        if (!str_starts_with($dir, '/')) {
            $dir = rtrim($env->basePath(), '/') . '/' . ltrim($dir, '/');
        }

        $partMaxBytes = max(
            65536,
            $env->int('SESSION_RECORDING_PART_BYTES', 5 * 1024 * 1024),
        );

        return new self($enabled, $dir, $partMaxBytes);
    }
}
