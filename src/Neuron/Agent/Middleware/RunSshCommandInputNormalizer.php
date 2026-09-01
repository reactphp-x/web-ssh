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
        $requested = null;
        if (array_key_exists('timeout_sec', $inputs)) {
            $raw = $inputs['timeout_sec'];
            if ($raw !== null && $raw !== '') {
                $requested = (int) $raw;
            }
        }

        $inputs['timeout_sec'] = CommandTimeoutResolver::resolve($requested, $default, $max);
        $tool->setInputs($inputs);
    }
}
