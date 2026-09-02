<?php

declare(strict_types=1);

namespace App\Tests\Ssh;

use App\Ssh\OpenSshWorkspace;
use App\Ssh\Ssh2Client;
use App\Ssh\SshTarget;
use PHPUnit\Framework\TestCase;

final class Ssh2ClientExecCommandTest extends TestCase
{
    public function testBuildExecShellCommandUsesPseudoTty(): void
    {
        $workspace = OpenSshWorkspace::prepare(new SshTarget('127.0.0.1', 22, 'root'), 1.0);

        try {
            $command = Ssh2Client::buildExecShellCommand($workspace, 'ls', 120, 40);

            self::assertStringContainsString('command -v script', $command);
            self::assertStringContainsString('script -c : /dev/null', $command);
            self::assertStringContainsString('script -qefc', $command);
            self::assertStringContainsString('script -qF /dev/null sh -c', $command);
            self::assertStringContainsString('zsh -ic', $command);
            self::assertStringContainsString(
                base64_encode("stty cols 120 rows 40 2>/dev/null\nexport TERM=\${TERM:-xterm-256color}\nls"),
                $command,
            );
        } finally {
            $workspace->cleanup();
        }
    }
}
