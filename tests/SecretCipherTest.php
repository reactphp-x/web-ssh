<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    public function testEncryptsAndDecrypts(): void
    {
        $cipher = SecretCipher::fromAppKey('base64:' . base64_encode(str_repeat('a', 32)));
        $encrypted = $cipher->encrypt('secret-value');
        self::assertNotSame('secret-value', $encrypted);
        self::assertSame('secret-value', $cipher->decrypt($encrypted));
    }
}
