<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ssh\SshErrorFormatter;
use App\Ssh\SshTarget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SshErrorFormatterTest extends TestCase
{
    public function testFormatsAuthenticationFailureWithExplanation(): void
    {
        $target = new SshTarget('10.0.0.1', 22, 'root', identityFile: '/root/.ssh/id_rsa');
        $formatted = SshErrorFormatter::format(
            new RuntimeException('SSH public key authentication failed.'),
            $target,
        );

        self::assertStringContainsString('10.0.0.1', $formatted['message']);
        self::assertStringContainsString('authentication rejected', $formatted['message']);
        self::assertStringContainsString('Target: root@10.0.0.1:22', $formatted['detail']);
        self::assertStringContainsString('RuntimeException', $formatted['detail']);
    }
}
