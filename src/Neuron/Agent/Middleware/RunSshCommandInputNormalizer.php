<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Ssh\CommandTimeoutResolver;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

final class RunSshCommandInputNormalizer
{
    public static function apply(ToolInterface $tool, int $default, int $max): void
    {
        if (!$tool instanceof Tool) {
            return;
        }

        $inputs = $tool->getInputs();
        self::applyTimeout($inputs, $default, $max);
        $tool->setInputs($inputs);
    }

    /**
     * @return string|null error message when terminal run_ssh_command inputs are invalid
     */
    public static function applyTerminal(ToolInterface $tool, int $default, int $max): ?string
    {
        if (!$tool instanceof Tool) {
            return null;
        }

        $inputs = self::normalizeAliases($tool->getInputs());
        $command = self::nonEmptyString($inputs['command'] ?? null);
        $reason = self::nonEmptyString($inputs['reason'] ?? null) ?? '未说明';

        if ($command === null) {
            $tool->setInputs(self::buildTerminalInputs($inputs, '', $reason, $default, $max));

            return '缺少 command：run_ssh_command 必须包含要执行的 shell 命令。';
        }

        $tool->setInputs(self::buildTerminalInputs($inputs, $command, $reason, $default, $max));

        return null;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public static function normalizeAliases(array $inputs): array
    {
        if (!array_key_exists('command', $inputs) && array_key_exists('cmd', $inputs)) {
            $inputs['command'] = $inputs['cmd'];
        }

        if (!array_key_exists('host_id', $inputs) && array_key_exists('host', $inputs)) {
            $inputs['host_id'] = $inputs['host'];
        }

        if (!array_key_exists('host_id', $inputs) && array_key_exists('hostId', $inputs)) {
            $inputs['host_id'] = $inputs['hostId'];
        }

        if (!array_key_exists('reason', $inputs) && array_key_exists('description', $inputs)) {
            $inputs['reason'] = $inputs['description'];
        }

        return $inputs;
    }

    public static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private static function buildTerminalInputs(
        array $inputs,
        string $command,
        string $reason,
        int $default,
        int $max,
    ): array {
        $inputs['command'] = $command;
        $inputs['reason'] = $reason;
        self::applyTimeout($inputs, $default, $max);

        return $inputs;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public static function applyTimeoutToInputs(array &$inputs, int $default, int $max): void
    {
        $requested = null;
        if (array_key_exists('timeout_sec', $inputs)) {
            $raw = $inputs['timeout_sec'];
            if ($raw !== null && $raw !== '') {
                $requested = (int) $raw;
            }
        }

        $inputs['timeout_sec'] = CommandTimeoutResolver::resolve($requested, $default, $max);
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private static function applyTimeout(array &$inputs, int $default, int $max): void
    {
        self::applyTimeoutToInputs($inputs, $default, $max);
    }
}
