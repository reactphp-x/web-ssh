<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class JsonResponse
{
    public static function json(mixed $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public static function error(string $message, int $status = 400, array $extra = []): ResponseInterface
    {
        return self::json(['message' => $message] + $extra, $status);
    }
}
