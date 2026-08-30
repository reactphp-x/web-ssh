<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

/**
 * ask_user 等待用户反馈时的占位 handler（WorkflowInterrupt 持久化前必须可序列化）。
 */
final class AskUserPendingHandler
{
    public function __invoke(mixed ...$args): string
    {
        return 'Waiting for user feedback.';
    }
}
