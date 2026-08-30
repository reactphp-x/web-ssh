<?php

declare(strict_types=1);

namespace App\Neuron\HttpClient;

use NeuronAI\HttpClient\StreamInterface;
use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;
use Throwable;

use function React\Async\await;

/**
 * Adapts ReactPHP ReadableStreamInterface to Neuron StreamInterface via await().
 */
final class ReactStream implements StreamInterface
{
    private bool $eof = false;

    private string $buffer = '';

    private bool $streamEnded = false;

    private bool $closed = false;

    public function __construct(
        private readonly ReadableStreamInterface $stream
    ) {
        $this->stream->on('data', function (string $chunk): void {
            $this->buffer .= $chunk;
        });
        $this->stream->on('end', function (): void {
            $this->streamEnded = true;
        });
        $this->stream->on('error', function (): void {
            $this->streamEnded = true;
            $this->eof = true;
        });
        $this->stream->on('close', function (): void {
            $this->streamEnded = true;
        });

        $this->stream->resume();
    }

    public function eof(): bool
    {
        return $this->buffer === '' && ($this->eof || $this->streamEnded);
    }

    public function read(int $length): string
    {
        try {
            if ($this->buffer !== '') {
                $result = substr($this->buffer, 0, $length);
                $this->buffer = substr($this->buffer, strlen($result));

                return $result;
            }

            if ($this->streamEnded || !$this->stream->isReadable()) {
                $this->eof = true;

                return '';
            }

            $this->waitForData();

            if ($this->buffer !== '') {
                $result = substr($this->buffer, 0, $length);
                $this->buffer = substr($this->buffer, strlen($result));

                return $result;
            }

            $this->eof = true;

            return '';
        } catch (Throwable) {
            $this->eof = true;

            return '';
        }
    }

    public function readLine(): string
    {
        $line = '';

        try {
            while (!$this->eof()) {
                $byte = $this->read(1);
                if ($byte === '') {
                    return $line;
                }
                $line .= $byte;
                if ($byte === "\n") {
                    break;
                }
            }
        } catch (Throwable) {
            $this->eof = true;
        }

        return $line;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->eof = true;
        $this->streamEnded = true;
        $this->buffer = '';
        $this->stream->close();
    }

    private function waitForData(): void
    {
        if ($this->buffer !== '' || $this->streamEnded || !$this->stream->isReadable()) {
            return;
        }

        $deferred = new Deferred();

        $this->stream->once('data', function () use ($deferred): void {
            $deferred->resolve(null);
        });
        $this->stream->once('end', function () use ($deferred): void {
            $deferred->resolve(null);
        });
        $this->stream->once('error', function (Throwable $e) use ($deferred): void {
            $deferred->reject($e);
        });
        $this->stream->once('close', function () use ($deferred): void {
            $this->streamEnded = true;
            $deferred->resolve(null);
        });

        await($deferred->promise());
    }
}
