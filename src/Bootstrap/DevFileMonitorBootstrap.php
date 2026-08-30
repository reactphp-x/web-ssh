<?php

declare(strict_types=1);

namespace App\Bootstrap;

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use ReactphpX\Framework\Application;
use ReactphpX\Framework\FileMonitor;
use ReactX\Worker\Worker;

final class DevFileMonitorBootstrap
{
    /**
     * @param string|list<string> $paths
     */
    public static function register(Application $application, string|array $paths, float $interval): void
    {
        $application->worker(
            name: 'FileMonitor',
            count: 1,
            start: static function (Worker $worker) use ($paths, $interval): void {
                if (Worker::$daemonize) {
                    Worker::log('Daemon mode, file monitor disabled');

                    return;
                }

                // stream_select is safe after pcntl_fork(); ext-uv is not.
                Loop::set(new StreamSelectLoop());

                $monitor = new FileMonitor($paths, $interval);
                $monitor->start();

                $previous = $worker->onWorkerStop;
                $worker->onWorkerStop = static function (Worker $worker) use ($previous, $monitor): void {
                    if (is_callable($previous)) {
                        $previous($worker);
                    }
                    $monitor->stop();
                };

                Worker::log('Development file monitor started');
            },
            reloadable: false,
        );
    }
}
