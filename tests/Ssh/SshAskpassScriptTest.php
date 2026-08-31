<?php

declare(strict_types=1);

namespace App\Tests\Ssh;

use App\Ssh\OpenSshWorkspace;
use App\Ssh\SshTarget;
use PHPUnit\Framework\TestCase;

final class SshAskpassScriptTest extends TestCase
{
    public function testAskpassScriptResolvesProjectAutoloadFromInstalledPath(): void
    {
        $workspace = OpenSshWorkspace::prepare(
            new SshTarget('127.0.0.1', 22, 'root', password: '269E&@#YEO2rhPIS'),
            1.0,
        );

        try {
            self::assertStringEndsWith('/bin/ssh-askpass.php', $workspace->askpassPath);
            self::assertFileExists($workspace->askpassPath);

            $projectRoot = dirname(__DIR__, 2);
            $command = escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($workspace->askpassPath) . ' '
                . escapeshellarg("root@127.0.0.1's password:");

            $output = [];
            $exitCode = 0;
            exec(
                'WEBSSH_ROOT=' . escapeshellarg($projectRoot)
                . ' WEBSSH_ASKPASS_MAP=' . escapeshellarg($workspace->askpassMapPath)
                . ' ' . $command . ' 2>/dev/null',
                $output,
                $exitCode,
            );

            self::assertSame(0, $exitCode);
            self::assertSame('269E&@#YEO2rhPIS', implode("\n", $output));
        } finally {
            $workspace->cleanup();
        }
    }
}
