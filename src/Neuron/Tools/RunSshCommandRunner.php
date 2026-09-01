<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Ssh\SshToolContext;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Async\await;

final class RunSshCommandRunner
{
    /**
     * @return array<string, mixed>
     */
    public static function run(string $connId, string $command, string $reason, int $timeoutSec): array
    {
        $command = trim($command);
        $reason = trim($reason);
        if ($command === '') {
            throw new RuntimeException('命令不能为空。');
        }

        $bridge = SshToolContext::bridge();
        if (!$bridge->isConnected($connId)) {
            throw new RuntimeException('SSH 会话未连接。');
        }

        try {
            /** @var PromiseInterface $promise */
            $promise = $bridge->runCommand($connId, $command, $timeoutSec);
            $result = await($promise);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'command' => $command,
                'reason' => $reason,
                'timeout_sec' => $timeoutSec,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'command' => $command,
            'reason' => $reason,
            'timeout_sec' => $timeoutSec,
            'output' => $result->output,
            'exit_code' => $result->exitCode,
            'timed_out' => $result->timedOut,
        ];
    }
}
