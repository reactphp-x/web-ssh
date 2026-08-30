<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Support\WorkerLog;
use React\EventLoop\Loop as ReactLoop;
use Throwable;

final class SshTerminalSession
{
    private ?Ssh2Client $client = null;

    public function connect(
        SshTarget $target,
        callable $onOutput,
        callable $onConnected,
        callable $onError,
        ?callable $onExit = null,
        int $cols = 80,
        int $rows = 24,
    ): void {
        ReactLoop::addTimer(0.0, function () use ($target, $cols, $rows, $onOutput, $onConnected, $onError, $onExit): void {
            try {
                WorkerLog::info(sprintf(
                    'SSH connecting target=%s@%s:%d uri=%s cols=%d rows=%d',
                    $target->user,
                    $target->host,
                    $target->port,
                    $target->uri(),
                    $cols,
                    $rows,
                ));

                $client = new Ssh2Client();
                $client->connect($target);

                WorkerLog::info(sprintf(
                    'SSH transport ready target=%s@%s:%d',
                    $target->user,
                    $target->host,
                    $target->port,
                ));

                $client->openShell(ReactLoop::get(), $cols, $rows, $onOutput, $onExit);
                $this->client = $client;

                WorkerLog::info(sprintf(
                    'SSH shell started target=%s@%s:%d',
                    $target->user,
                    $target->host,
                    $target->port,
                ));

                $onConnected();
            } catch (Throwable $exception) {
                WorkerLog::error(sprintf(
                    'SSH connect exception target=%s@%s:%d error=%s',
                    $target->user,
                    $target->host,
                    $target->port,
                    $exception->getMessage(),
                ));
                $onError($exception);
            }
        });
    }

    public function write(string $data): void
    {
        $this->client?->write($data);
    }

    public function resize(int $cols, int $rows): void
    {
        $this->client?->resize($cols, $rows);
    }

    public function close(): void
    {
        $this->client?->disconnect(ReactLoop::get());
        $this->client = null;
    }
}
