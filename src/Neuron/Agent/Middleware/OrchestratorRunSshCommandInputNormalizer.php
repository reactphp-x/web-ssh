<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

final class OrchestratorRunSshCommandInputNormalizer
{
    /**
     * @return string|null error message when orchestrator run_ssh_command inputs are invalid
     */
    public static function apply(
        ToolInterface $tool,
        int $defaultTimeout,
        int $maxTimeout,
        ?int $activeHostId,
    ): ?string {
        if (!$tool instanceof Tool) {
            return null;
        }

        $inputs = RunSshCommandInputNormalizer::normalizeAliases($tool->getInputs());
        $hostId = self::resolveHostId($inputs, $activeHostId);
        $command = RunSshCommandInputNormalizer::nonEmptyString($inputs['command'] ?? null);
        $reason = RunSshCommandInputNormalizer::nonEmptyString($inputs['reason'] ?? null) ?? '未说明';

        if ($hostId === null) {
            $tool->setInputs(self::buildInputs($inputs, 0, '', $reason, $defaultTimeout, $maxTimeout));

            return '缺少 host_id：请先调用 list_hosts 选择目标主机，或在 run_ssh_command 中传入 host_id。';
        }

        if ($command === null) {
            $tool->setInputs(self::buildInputs($inputs, $hostId, '', $reason, $defaultTimeout, $maxTimeout));

            return '缺少 command：run_ssh_command 必须包含要执行的 shell 命令。';
        }

        $tool->setInputs(self::buildInputs($inputs, $hostId, $command, $reason, $defaultTimeout, $maxTimeout));

        return null;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public static function resolveHostId(array $inputs, ?int $activeHostId): ?int
    {
        if (array_key_exists('host_id', $inputs)) {
            $raw = $inputs['host_id'];
            if ($raw !== null && $raw !== '') {
                $parsed = (int) $raw;
                if ($parsed > 0) {
                    return $parsed;
                }
            }
        }

        if ($activeHostId !== null && $activeHostId > 0) {
            return $activeHostId;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private static function buildInputs(
        array $inputs,
        int $hostId,
        string $command,
        string $reason,
        int $defaultTimeout,
        int $maxTimeout,
    ): array {
        $inputs['host_id'] = $hostId;
        $inputs['command'] = $command;
        $inputs['reason'] = $reason;
        RunSshCommandInputNormalizer::applyTimeoutToInputs($inputs, $defaultTimeout, $maxTimeout);

        return $inputs;
    }
}
