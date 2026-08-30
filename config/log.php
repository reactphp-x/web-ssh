<?php

declare(strict_types=1);

use React\Filesystem\Fallback\Adapter as FilesystemFallbackAdapter;
use ReactphpX\Framework\Environment;
use ReactphpX\Log\Log;
use ReactphpX\Log\LogManager;

function isStandardStreamUsable(mixed $stream): bool
{
    if (!is_resource($stream)) {
        return false;
    }

    $meta = stream_get_meta_data($stream);
    $uri = (string) ($meta['uri'] ?? '');

    if ($uri === '/dev/null' || str_ends_with($uri, '/dev/null')) {
        return false;
    }

    return @fwrite($stream, '') !== false;
}

function isStdoutUsable(): bool
{
    return defined('STDOUT') && isStandardStreamUsable(STDOUT);
}

function isStderrUsable(): bool
{
    return defined('STDERR') && isStandardStreamUsable(STDERR);
}

/**
 * @return array<string, mixed>
 */
function buildLogConfig(Environment $environment): array
{
    $logPath = $environment->string('LOG_PATH', $environment->basePath() . '/storage/logs/app.log');
    $databaseLogPath = $environment->string(
        'DB_LOG_PATH',
        $environment->basePath() . '/storage/logs/database.log',
    );
    $level = $environment->string('LOG_LEVEL', 'debug');
    $databaseLevel = $environment->string('DB_LOG_LEVEL', $level);
    $filesystemAdapter = new FilesystemFallbackAdapter();
    $stdoutUsable = isStdoutUsable();
    $stderrUsable = isStderrUsable();

    $channels = [
        'single' => [
            'driver' => 'single',
            'path' => $logPath,
            'adapter' => $filesystemAdapter,
            'level' => $level,
            'formatter' => 'line',
        ],
        'database' => [
            'driver' => 'single',
            'path' => $databaseLogPath,
            'adapter' => $filesystemAdapter,
            'level' => $databaseLevel,
            'formatter' => 'line',
        ],
    ];

    if ($stdoutUsable) {
        $channels['stdout'] = [
            'driver' => 'stdout',
            'level' => $level,
            'formatter' => 'console',
        ];
    }

    if ($stderrUsable) {
        $channels['stderr'] = [
            'driver' => 'stderr',
            'level' => $level,
            'formatter' => 'console',
        ];
    }

    $stackChannels = [];
    if ($stdoutUsable) {
        $stackChannels[] = 'stdout';
    }
    $stackChannels[] = 'single';
    $channels['stack'] = [
        'driver' => 'stack',
        'channels' => $stackChannels,
    ];

    $defaultChannel = $environment->string('LOG_CHANNEL', $stdoutUsable ? 'stdout' : 'single');
    if ($defaultChannel === 'stdout' && !$stdoutUsable) {
        $defaultChannel = 'single';
    }
    if ($defaultChannel === 'stderr' && !$stderrUsable) {
        $defaultChannel = 'single';
    }

    return [
        'default' => $defaultChannel,
        'channels' => $channels,
    ];
}

function configureLogging(Environment $environment): LogManager
{
    $config = buildLogConfig($environment);
    Log::configure($config);

    return new LogManager($config);
}
