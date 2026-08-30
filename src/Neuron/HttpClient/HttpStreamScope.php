<?php

declare(strict_types=1);

namespace App\Neuron\HttpClient;

/**
 * Tracks LLM HTTP streams opened during one SSE response so a client disconnect can close them.
 */
final class HttpStreamScope
{
    /** @var list<ReactStream> */
    private array $streams = [];

    private bool $closed = false;

    public function attach(ReactStream $stream): void
    {
        if ($this->closed) {
            $stream->close();

            return;
        }

        $this->streams[] = $stream;
    }

    public function closeAll(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->streams = [];
    }
}
