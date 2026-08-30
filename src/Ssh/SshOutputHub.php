<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Http\Sse;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Stream\ThroughStream;

final class SshOutputHub
{
    private const HISTORY_LIMIT = 2000;

    /** @var list<ThroughStream> */
    private array $subscribers = [];

    /** @var list<array{0: string, 1: mixed}> */
    private array $history = [];

    private bool $closed = false;

    private ?TimerInterface $keepalive = null;

    public function __construct()
    {
        $this->keepalive = Loop::addPeriodicTimer(15.0, function (): void {
            foreach ($this->subscribers as $sse) {
                if ($sse->isWritable()) {
                    $sse->write(": keepalive\n\n");
                }
            }
        });
    }

    public function attach(ThroughStream $sse, bool $joined = false, string $joinedMessage = 'joined running session'): void
    {
        $sse->on('close', function () use ($sse): void {
            $this->subscribers = array_values(array_filter(
                $this->subscribers,
                static fn (ThroughStream $subscriber): bool => $subscriber !== $sse,
            ));
        });

        Loop::futureTick(function () use ($sse, $joined, $joinedMessage): void {
            if (!$sse->isWritable()) {
                return;
            }
            if ($joined) {
                Sse::write($sse, 'status', [
                    'message' => $joinedMessage,
                    'replay' => true,
                ]);
            }
            foreach ($this->history as [$event, $data]) {
                Sse::write($sse, $event, $data);
            }
            if ($this->closed) {
                Sse::end($sse);

                return;
            }
            $this->subscribers[] = $sse;
        });
    }

    public function write(string $event, mixed $data): void
    {
        if ($this->closed) {
            return;
        }

        $this->history[] = [$event, $data];
        if (count($this->history) > self::HISTORY_LIMIT) {
            $start = $this->history[0][0] === 'start' ? [array_shift($this->history)] : [];
            $this->history = array_merge(
                $start,
                array_slice($this->history, -(self::HISTORY_LIMIT - count($start))),
            );
        }

        foreach ($this->subscribers as $sse) {
            Sse::write($sse, $event, $data);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->keepalive !== null) {
            Loop::cancelTimer($this->keepalive);
            $this->keepalive = null;
        }
        foreach ($this->subscribers as $sse) {
            Sse::end($sse);
        }
        $this->subscribers = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
