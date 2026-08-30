<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use RuntimeException;

final class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct(private readonly string $key)
    {
        if (strlen($this->key) !== 32) {
            throw new InvalidArgumentException('APP_KEY must decode to exactly 32 bytes for AES-256.');
        }
    }

    public static function fromAppKey(string $appKey): self
    {
        $appKey = trim($appKey);
        if ($appKey === '') {
            throw new InvalidArgumentException('APP_KEY is required for credential encryption.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new InvalidArgumentException('APP_KEY base64 payload must be 32 bytes.');
            }

            return new self($decoded);
        }

        if (strlen($appKey) === 64 && ctype_xdigit($appKey)) {
            return new self(hex2bin($appKey) ?: throw new InvalidArgumentException('Invalid APP_KEY hex.'));
        }

        throw new InvalidArgumentException('APP_KEY must use base64: or 64-char hex format.');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt secret.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new RuntimeException('Invalid encrypted secret payload.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt secret.');
        }

        return $plaintext;
    }
}
