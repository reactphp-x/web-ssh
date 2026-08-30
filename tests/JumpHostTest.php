<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ssh\JumpHostChain;
use App\Ssh\SshAskpass;
use App\Ssh\SshErrorFormatter;
use App\Ssh\SshJumpConfig;
use App\Ssh\SshTarget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JumpHostTest extends TestCase
{
    public function testWouldCycleDetectsSelfReference(): void
    {
        self::assertTrue(JumpHostChain::wouldCycle([1 => null], 1, 1));
        self::assertFalse(JumpHostChain::wouldCycle([1 => null, 2 => null], 1, 2));
    }

    public function testWouldCycleDetectsLoop(): void
    {
        $map = [
            1 => 2,
            2 => 3,
            3 => 1,
        ];

        self::assertTrue(JumpHostChain::wouldCycle($map, 1, 2));
        self::assertTrue(JumpHostChain::wouldCycle($map, 4, 1));
    }

    public function testWouldCycleRejectsDeepChain(): void
    {
        $map = [
            1 => 2,
            2 => 3,
            3 => 4,
            4 => 5,
            5 => 6,
            6 => null,
        ];

        self::assertTrue(JumpHostChain::wouldCycle($map, 10, 1));
        self::assertFalse(JumpHostChain::wouldCycle([1 => null, 2 => 1], 3, 2));
    }

    public function testHopsFromTargetAreOutermostFirst(): void
    {
        $outer = new SshTarget('1.1.1.1', 22, 'jump1', password: 'a');
        $inner = new SshTarget('10.0.0.2', 22, 'jump2', password: 'b', jump: $outer);
        $target = new SshTarget('10.0.0.8', 22, 'root', password: 'c', jump: $inner);

        $hops = JumpHostChain::hopsFromTarget($target);
        self::assertCount(2, $hops);
        self::assertSame('1.1.1.1', $hops[0]->host);
        self::assertSame('10.0.0.2', $hops[1]->host);
        self::assertSame('1.1.1.1', JumpHostChain::outermost($target)->host);
    }

    public function testAskpassResolvesPasswordAndPassphrase(): void
    {
        $map = [
            'passwords' => [
                'ubuntu@bastion.example' => 'jump-pass',
                'bastion.example' => 'host-pass',
            ],
            'passphrases' => [
                '/tmp/id_rsa' => 'key-phrase',
            ],
            'default' => 'fallback',
        ];

        self::assertSame('jump-pass', SshAskpass::secretForPrompt($map, "ubuntu@bastion.example's password:"));
        self::assertSame('key-phrase', SshAskpass::secretForPrompt($map, "Enter passphrase for key '/tmp/id_rsa':"));
        self::assertSame('fallback', SshAskpass::secretForPrompt($map, 'unknown prompt'));
    }

    public function testJumpConfigRendersProxyJumpChain(): void
    {
        $hops = [
            [
                'alias' => 'hop-0',
                'target' => new SshTarget('bastion.example', 22, 'ubuntu', password: 'secret'),
                'identityFile' => null,
            ],
            [
                'alias' => 'hop-1',
                'target' => new SshTarget('10.0.0.2', 2222, 'ops', identityFile: '/tmp/id_ed25519'),
                'identityFile' => '/tmp/id_ed25519',
            ],
        ];

        $config = SshJumpConfig::render($hops, 12.4);

        self::assertStringContainsString('ConnectTimeout 13', $config);
        self::assertStringContainsString('Host hop-0', $config);
        self::assertStringContainsString('HostName bastion.example', $config);
        self::assertStringContainsString('PubkeyAuthentication no', $config);
        self::assertStringContainsString('Host hop-1', $config);
        self::assertStringContainsString('ProxyJump hop-0', $config);
        self::assertStringContainsString('IdentityFile /tmp/id_ed25519', $config);
    }

    public function testJumpConfigRendersTargetSession(): void
    {
        $hops = [
            [
                'alias' => 'hop-0',
                'target' => new SshTarget('bastion.example', 22, 'ubuntu', password: 'secret'),
                'identityFile' => null,
            ],
        ];
        $target = new SshTarget('10.0.0.8', 22, 'root', identityFile: '/tmp/id_rsa');

        $config = SshJumpConfig::renderSession($hops, $target, '/tmp/id_rsa', 10.0);

        self::assertStringContainsString('Host target', $config);
        self::assertStringContainsString('HostName 10.0.0.8', $config);
        self::assertStringContainsString('ProxyJump hop-0', $config);
        self::assertStringContainsString('IdentityFile /tmp/id_rsa', $config);
    }

    public function testErrorFormatterIncludesJumpChain(): void
    {
        $jump = new SshTarget('1.2.3.4', 22, 'ubuntu', password: 'x');
        $target = new SshTarget('10.0.0.8', 22, 'root', identityFile: '/root/.ssh/id_rsa', jump: $jump);
        $formatted = SshErrorFormatter::format(
            new RuntimeException('Jump host tunnel failed: permission denied'),
            $target,
        );

        self::assertStringContainsString('via ubuntu@1.2.3.4:22', $formatted['message']);
        self::assertStringContainsString('jump host', $formatted['message']);
        self::assertStringContainsString('Jump: ubuntu@1.2.3.4:22', $formatted['detail']);
    }
}
