<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class StaticFileHandler
{
    private readonly string $publicDir;

    public function __construct(string $publicDir)
    {
        $resolved = realpath($publicDir);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Public directory does not exist: ' . $publicDir);
        }

        $this->publicDir = $resolved;
    }

    public function serve(string $relativePath): ResponseInterface
    {
        $relativePath = str_replace('\\', '/', trim($relativePath, '/'));
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return $this->notFound();
        }

        $candidate = $this->publicDir . '/' . $relativePath;
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved) || !$this->isInsidePublicDir($resolved)) {
            return $this->notFound();
        }

        $headers = [
            'Content-Type' => self::contentType($resolved),
        ];

        if (str_starts_with($relativePath, 'vendor/')) {
            $headers['Cache-Control'] = 'public, max-age=86400, immutable';
        }

        return new Response(200, $headers, (string) file_get_contents($resolved));
    }

    private function isInsidePublicDir(string $path): bool
    {
        return str_starts_with($path, $this->publicDir . DIRECTORY_SEPARATOR)
            || $path === $this->publicDir;
    }

    private function notFound(): ResponseInterface
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found');
    }

    private static function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };
    }
}
