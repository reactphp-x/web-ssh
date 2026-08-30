<?php

declare(strict_types=1);

namespace App\Http;

use React\Http\Message\Response;
use React\Stream\ThroughStream;

final class Sse
{
    public static function response(ThroughStream $stream): Response
    {
        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
            $stream,
        );
    }

    public static function write(ThroughStream $stream, string $event, mixed $data): void
    {
        if (!$stream->isWritable()) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stream->write('event: ' . $event . "\ndata: " . $json . "\n\n");
    }

    public static function end(ThroughStream $stream): void
    {
        if ($stream->isWritable()) {
            $stream->end();
        }
    }
}
