<?php

declare(strict_types=1);

namespace App\Ssh;

use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexStreamInterface;
use RuntimeException;
use Throwable;

final class Ssh2Client
{
    private static float $connectTimeout = 10.0;

    private ?Process $process = null;

    private ?OpenSshWorkspace $workspace = null;

    private int $cols = 80;

    private int $rows = 24;

    public static function setConnectTimeout(float $seconds): void
    {
        self::$connectTimeout = max(1.0, $seconds);
    }

    public static function connectTimeout(): float
    {
        return self::$connectTimeout;
    }

    public static function probe(SshTarget $target): void
    {
        $reachable = $target->jump !== null
            ? JumpHostChain::outermost($target)
            : $target;
        self::assertTcpReachable($reachable, self::$connectTimeout);

        $workspace = OpenSshWorkspace::prepare($target, self::$connectTimeout);

        try {
            self::runProbe($workspace, $target);
        } finally {
            $workspace->cleanup();
        }
    }

    public function connect(SshTarget $target): void
    {
        try {
            $reachable = $target->jump !== null
                ? JumpHostChain::outermost($target)
                : $target;
            self::assertTcpReachable($reachable, self::$connectTimeout);
            $this->workspace = OpenSshWorkspace::prepare($target, self::$connectTimeout);
        } catch (Throwable $exception) {
            $this->disconnect();
            throw $exception;
        }
    }

    /**
     * @param callable(string): void $onOutput
     * @param callable(): void|null $onExit
     */
    public function openShell(
        LoopInterface $loop,
        int $cols,
        int $rows,
        callable $onOutput,
        ?callable $onExit = null,
    ): void {
        if ($this->workspace === null) {
            throw new RuntimeException('SSH session is not connected.');
        }

        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);

        $process = new Process(
            self::buildCommand($this->workspace, probe: false, cols: $this->cols, rows: $this->rows),
            $this->workspace->directory,
            self::processEnv($this->workspace, $this->cols, $this->rows),
            [
                ['pty'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
        );

        $emit = static function (string $chunk) use ($onOutput): void {
            if ($chunk !== '') {
                $onOutput($chunk);
            }
        };

        $process->start($loop);
        if ($process->stdin instanceof DuplexStreamInterface) {
            PtySize::set($process->stdin, $this->rows, $this->cols);
        }
        $process->stdout?->on('data', $emit);
        $process->stderr?->on('data', $emit);
        if ($process->stdin instanceof DuplexStreamInterface) {
            $process->stdin->on('data', $emit);
        }

        $process->on('exit', function () use ($onExit): void {
            $this->process = null;
            $this->workspace?->cleanup();
            $this->workspace = null;
            if ($onExit !== null) {
                $onExit();
            }
        });

        $this->process = $process;
    }

    public function write(string $data): void
    {
        if ($this->process?->stdin === null) {
            return;
        }

        $this->process->stdin->write($data);
    }

    public function resize(int $cols, int $rows): void
    {
        if ($this->process === null) {
            return;
        }

        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);

        if ($this->process->stdin instanceof DuplexStreamInterface) {
            PtySize::set($this->process->stdin, $this->rows, $this->cols);
        }

        if (\defined('SIGWINCH')) {
            $this->process->terminate((int) \constant('SIGWINCH'));
        }
    }

    public function disconnect(?LoopInterface $loop = null): void
    {
        if ($this->process !== null) {
            foreach ($this->process->pipes as $pipe) {
                $pipe->close();
            }
            $this->process->terminate();
            $this->process = null;
        }

        $this->workspace?->cleanup();
        $this->workspace = null;
    }

    public static function buildExecShellCommand(
        OpenSshWorkspace $workspace,
        string $command,
        int $cols = 80,
        int $rows = 24,
    ): string {
        $scriptBody = implode("\n", [
            sprintf('stty cols %d rows %d 2>/dev/null', max(1, $cols), max(1, $rows)),
            'export TERM=${TERM:-xterm-256color}',
            $command,
        ]);
        $payload = base64_encode($scriptBody);
        $shellRun = sprintf(
            'if command -v zsh >/dev/null 2>&1; then exec zsh -ic "$(printf \'%%s\' \'%s\' | base64 -d)"; else exec bash -lc "$(printf \'%%s\' \'%s\' | base64 -d)"; fi',
            $payload,
            $payload,
        );
        $shellRunQuoted = escapeshellarg($shellRun);
        // util-linux: script -qefc CMD /dev/null  |  BSD/macOS: script -qF /dev/null sh -c CMD
        $withUtilLinuxScript = sprintf('script -qefc %s /dev/null', $shellRunQuoted);
        $withBsdScript = sprintf('script -qF /dev/null sh -c %s', $shellRunQuoted);
        // Prefer script(1) for a PTY; detect util-linux (-c) vs BSD (no -c) at runtime.
        $remote = sprintf(
            'if command -v script >/dev/null 2>&1; then if script -c : /dev/null 2>/dev/null; then %s; else %s; fi; else %s; fi',
            $withUtilLinuxScript,
            $withBsdScript,
            $shellRun,
        );

        $ssh = escapeshellarg(self::sshBinary());
        $config = escapeshellarg($workspace->configPath);
        $alias = escapeshellarg(OpenSshWorkspace::TARGET_ALIAS);

        return sprintf(
            'exec %s -F %s -T -o BatchMode=no -o RequestTTY=no %s %s',
            $ssh,
            $config,
            $alias,
            escapeshellarg($remote),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function workspaceProcessEnv(OpenSshWorkspace $workspace): array
    {
        return self::processEnv($workspace);
    }

    private static function buildCommand(OpenSshWorkspace $workspace, bool $probe, int $cols = 80, int $rows = 24): string
    {
        $ssh = escapeshellarg(self::sshBinary());
        $config = escapeshellarg($workspace->configPath);
        $alias = escapeshellarg(OpenSshWorkspace::TARGET_ALIAS);

        if ($probe) {
            return sprintf(
                'exec %s -F %s -o BatchMode=no -o RequestTTY=no %s exit',
                $ssh,
                $config,
                $alias,
            );
        }

        return sprintf(
            'stty rows %d cols %d 2>/dev/null; exec %s -F %s -tt -o BatchMode=no %s',
            max(1, $rows),
            max(1, $cols),
            $ssh,
            $config,
            $alias,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function processEnv(OpenSshWorkspace $workspace, int $cols = 80, int $rows = 24): array
    {
        $env = getenv();
        if (!is_array($env) || $env === []) {
            $env = [];
            foreach ($_SERVER as $key => $value) {
                if (is_string($key) && is_string($value) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1) {
                    $env[$key] = $value;
                }
            }
        }

        $env['SSH_ASKPASS'] = $workspace->askpassPath;
        $env['SSH_ASKPASS_REQUIRE'] = 'force';
        $env['DISPLAY'] = $env['DISPLAY'] ?? ':0';
        $env['WEBSSH_ASKPASS_MAP'] = $workspace->askpassMapPath;
        $env['WEBSSH_ROOT'] = dirname(__DIR__, 2);
        $env['COLUMNS'] = (string) max(1, $cols);
        $env['LINES'] = (string) max(1, $rows);
        $env['LANG'] = 'C.UTF-8';
        $env['LC_ALL'] = 'C.UTF-8';
        $env['LC_CTYPE'] = 'C.UTF-8';
        $env['TERM'] = 'xterm-256color';
        unset($env['SSH_AUTH_SOCK']);

        return $env;
    }

    private static function runProbe(OpenSshWorkspace $workspace, SshTarget $target): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            self::buildCommand($workspace, probe: true),
            $descriptors,
            $pipes,
            $workspace->directory,
            self::processEnv($workspace),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start SSH probe.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $deadline = microtime(true) + self::$connectTimeout;
        $exitCode = null;

        while ($exitCode === null) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                proc_close($process);

                throw new RuntimeException(sprintf(
                    'SSH probe timed out within %.0fs.',
                    self::$connectTimeout,
                ));
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($pipe === $pipes[2]) {
                        $stderr .= $chunk;
                    }
                }
            }

            usleep(50000);
        }

        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim($stderr);
            $message = sprintf(
                'SSH probe to %s failed with exit code %d.',
                $target->uri(),
                $exitCode,
            );
            if ($detail !== '') {
                $message .= ' ' . $detail;
            }

            throw new RuntimeException($message);
        }
    }

    private static function sshBinary(): string
    {
        $resolved = trim((string) shell_exec('command -v ssh 2>/dev/null'));
        if ($resolved === '') {
            throw new RuntimeException('The OpenSSH client (`ssh`) is required in PATH.');
        }

        return $resolved;
    }

    private static function assertTcpReachable(SshTarget $target, float $timeoutSeconds): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $target->host . ':' . $target->port,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            $reason = $errstr !== '' ? $errstr : 'errno ' . $errno;

            throw new RuntimeException(sprintf(
                'TCP connect to %s failed within %.0fs: %s',
                $target->uri(),
                $timeoutSeconds,
                $reason,
            ));
        }

        fclose($socket);
    }
}
