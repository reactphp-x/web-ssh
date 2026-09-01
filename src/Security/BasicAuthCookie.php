<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BasicAuthCookie
{
    public const NAME = 'web_ssh_auth';

    public const TTL = 14400;

    private static bool $secure = false;

    private static ?string $signingKey = null;

    private static int $defaultTtl = self::TTL;

    public static function configure(bool $secure, string $signingKey, ?int $ttl = null): void
    {
        if (strlen($signingKey) !== 32) {
            throw new InvalidArgumentException('Basic auth cookie signing key must be 32 bytes.');
        }

        self::$secure = $secure;
        self::$signingKey = $signingKey;
        if ($ttl !== null) {
            self::$defaultTtl = $ttl;
        }
    }

    public static function read(ServerRequestInterface $request): ?string
    {
        $details = self::readDetails($request);

        return $details['username'] ?? null;
    }

    /**
     * @return array{username: string, expiresAt: int}|null
     */
    public static function readDetails(ServerRequestInterface $request): ?array
    {
        if (self::$signingKey === null) {
            return null;
        }

        $value = self::readRaw($request);
        if ($value === null) {
            return null;
        }

        return self::verifyDetails($value);
    }

    public static function attach(ResponseInterface $response, string $username, ?int $ttl = null): ResponseInterface
    {
        $ttl ??= self::$defaultTtl;

        if (self::$signingKey === null) {
            throw new InvalidArgumentException('Basic auth cookie is not configured.');
        }

        $payload = json_encode(['u' => $username, 'exp' => time() + $ttl], JSON_THROW_ON_ERROR);
        $payloadEncoded = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $payloadEncoded, self::$signingKey, true);
        $value = $payloadEncoded . '.' . self::base64UrlEncode($signature);

        return $response->withAddedHeader('Set-Cookie', self::buildCookie($value, $ttl));
    }

    public static function clear(ResponseInterface $response): ResponseInterface
    {
        $flags = 'Path=/; HttpOnly; SameSite=Strict; Max-Age=0';
        if (self::$secure) {
            $flags .= '; Secure';
        }

        return $response->withAddedHeader('Set-Cookie', self::NAME . '=; ' . $flags);
    }

    private static function readRaw(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Cookie');
        if ($header === '') {
            return null;
        }

        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if (!str_starts_with($part, self::NAME . '=')) {
                continue;
            }

            $value = rawurldecode(substr($part, strlen(self::NAME) + 1));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @return array{username: string, expiresAt: int}|null
     */
    private static function verifyDetails(string $value): ?array
    {
        if (self::$signingKey === null) {
            return null;
        }

        $parts = explode('.', $value, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = $parts;
        $expected = hash_hmac('sha256', $payloadEncoded, self::$signingKey, true);
        $signature = self::base64UrlDecode($signatureEncoded);
        if ($signature === null || !hash_equals($expected, $signature)) {
            return null;
        }

        $payload = self::base64UrlDecode($payloadEncoded);
        if ($payload === null) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $username = $decoded['u'] ?? null;
        $expiresAt = $decoded['exp'] ?? null;
        if (!is_string($username) || $username === '' || !is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt <= time()) {
            return null;
        }

        return [
            'username' => $username,
            'expiresAt' => $expiresAt,
        ];
    }

    private static function buildCookie(string $value, int $maxAge): string
    {
        $flags = 'Path=/; HttpOnly; SameSite=Strict; Max-Age=' . $maxAge;
        if (self::$secure) {
            $flags .= '; Secure';
        }

        return self::NAME . '=' . rawurlencode($value) . '; ' . $flags;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
