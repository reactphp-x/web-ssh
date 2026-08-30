<?php

declare(strict_types=1);

namespace App\Ssh;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Promise\reject;

final class SshExecRunner
{
    /**
     * @param callable(string): void|null $onChunk
     */
    public static function run(
        OpenSshWorkspace $workspace,
        string $command,
        int $timeoutSec,
        int $cols = 80,
        int $rows = 24,
        ?callable $onChunk = null,
    ): PromiseInterface {
        $command = trim($command);
        if ($command === '') {
            return reject(new RuntimeException('命令不能为空。'));
        }

        $deferred = new Deferred();
        $stdout = '';
        $stderr = '';
        $finished = false;
        $timedOut = false;

        try {
            $process = new Process(
                Ssh2Client::buildExecShellCommand($workspace, $command, $cols, $rows),
                $workspace->directory,
                Ssh2Client::workspaceProcessEnv($workspace),
                [
                    ['pipe', 'r'],
                    ['pipe', 'w'],
                    ['pipe', 'w'],
                ],
            );
        } catch (\Throwable $e) {
            return reject(new RuntimeException('无法启动 SSH 命令进程：' . $e->getMessage(), 0, $e));
        }

        $finish = function (?int $exitCode, bool $timedOut) use (
            &$finished,
            $deferred,
            &$stdout,
            &$stderr,
        ): void {
            if ($finished) {
                return;
            }
            $finished = true;

            $output = CommandOutputCollector::stripAnsi($stdout);
            $err = CommandOutputCollector::stripAnsi($stderr);
            if ($err !== '') {
                $output = $output !== '' ? $output . "\n" . $err : $err;
            }
            $output = trim(preg_replace("/\r\n?/", "\n", $output) ?? $output);

            if ($timedOut) {
                $output = $output !== ''
                    ? $output . "\n\n(命令超时，以上为部分输出)"
                    : '(命令超时，无输出)';
            }

            $deferred->resolve(new CommandResult(
                $output,
                $exitCode,
                $timedOut,
            ));
        };

        $process->start(Loop::get());

        if ($process->stdin === null) {
            $process->terminate();

            return reject(new RuntimeException('SSH 命令进程 stdin 不可用。'));
        }

        $process->stdin->end();

        $emit = static function (string $chunk, bool $isStderr) use (
            &$stdout,
            &$stderr,
            $onChunk,
        ): void {
            if ($chunk === '') {
                return;
            }

            if ($isStderr) {
                $stderr .= $chunk;
            } else {
                $stdout .= $chunk;
            }

            if ($onChunk !== null) {
                $onChunk($chunk);
            }
        };

        $process->stdout?->on('data', static function (string $chunk) use ($emit): void {
            $emit($chunk, false);
        });
        $process->stderr?->on('data', static function (string $chunk) use ($emit): void {
            $emit($chunk, true);
        });

        $timer = Loop::addTimer((float) max(1, $timeoutSec), static function () use (
            $process,
            &$finished,
            &$timedOut,
        ): void {
            if ($finished) {
                return;
            }

            $timedOut = true;
            $process->terminate();
        });

        $process->on('exit', static function (?int $exitCode) use ($timer, $finish, &$finished, &$timedOut): void {
            Loop::cancelTimer($timer);
            if ($finished) {
                return;
            }

            $finish($exitCode, $timedOut);
        });

        $process->on('error', static function (\Throwable $error) use ($timer, &$finished, $deferred): void {
            Loop::cancelTimer($timer);
            if ($finished) {
                return;
            }
            $finished = true;
            $deferred->reject(new RuntimeException('SSH 命令执行失败：' . $error->getMessage(), 0, $error));
        });

        return $deferred->promise();
    }
}
