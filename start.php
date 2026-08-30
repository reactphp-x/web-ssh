#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Http\WebAppFactory;
use App\Bootstrap\DevFileMonitorBootstrap;
use App\Log\LogRotationMonitor;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\StreamSelectLoop;
use ReactphpX\Framework\Application;
use ReactX\Worker\Worker;

require __DIR__ . '/vendor/autoload.php';

Worker::$stdoutFile = __DIR__ . '/reactphp-x-worker.log';

ReactLoop::set(new StreamSelectLoop());

$application = Application::create(__DIR__);
$timezone = $application->environment()->string('APP_TIMEZONE', 'Asia/Shanghai');
if (!@date_default_timezone_set($timezone)) {
    throw new InvalidArgumentException(sprintf('Invalid APP_TIMEZONE "%s".', $timezone));
}

$application->http(static function ($env) {
    return WebAppFactory::create($env);
});

$application->worker(
    name: 'LogRotation',
    count: 1,
    start: static function (Worker $worker) use ($application): void {
        $env = $application->environment();
        $monitor = new LogRotationMonitor(
            directory: $env->basePath() . '/storage/logs',
            maxFiles: $env->int('LOG_ROTATION_MAX_FILES', 14),
            checkInterval: $env->float('LOG_ROTATION_INTERVAL', 3600),
        );
        $monitor->start();

        $previous = $worker->onWorkerStop;
        $worker->onWorkerStop = static function (Worker $worker) use ($previous, $monitor): void {
            if (is_callable($previous)) {
                $previous($worker);
            }
            $monitor->stop();
        };
    },
    reloadable: false,
);

if ($application->environment()->string('APP_ENV', 'production') === 'development') {
    DevFileMonitorBootstrap::register(
        $application,
        [__DIR__ . '/src', __DIR__ . '/config', __DIR__ . '/public', __FILE__],
        $application->environment()->float('FILE_MONITOR_INTERVAL', 1.0),
    );
}

$application->run();
