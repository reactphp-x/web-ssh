<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Ssh\CommandOutputCollector;
use App\Ssh\OrchestratorToolContext;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Async\await;

final class OrchestratorRunSshCommandRunner
{
    /**
     * @return array<string, mixed>
     */
    public static function run(int $aiSessionId, int $hostId, string $command, string $reason, int $timeoutSec): array
    {
        OrchestratorToolContext::useSession($aiSessionId);

        $command = trim($command);
        $reason = trim($reason);
        if ($command === '') {
            throw new RuntimeException('命令不能为空。');
        }
        if ($hostId <= 0) {
            throw new RuntimeException('host_id 无效。');
        }

        $bridge = OrchestratorToolContext::execBridge();
        $username = OrchestratorToolContext::username();

        try {
            /** @var PromiseInterface $promise */
            $promise = $bridge->runCommand(
                $aiSessionId,
                $username,
                $hostId,
                $command,
                $timeoutSec,
            );
            $result = await($promise);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'host_id' => $hostId,
                'command' => $command,
                'reason' => $reason,
                'timeout_sec' => $timeoutSec,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'host_id' => $hostId,
            'command' => $command,
            'reason' => $reason,
            'timeout_sec' => $timeoutSec,
            'output' => CommandOutputCollector::sanitizeUtf8($result->output),
            'exit_code' => $result->exitCode,
            'timed_out' => $result->timedOut,
        ];
    }
}
