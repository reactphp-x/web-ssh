<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

/**
 * 可序列化的 ask_user 工具结果处理器（workflow 中断恢复时使用）。
 */
final class ToolFeedbackResultHandler
{
    public function __construct(
        private readonly string $jsonResult,
    ) {
    }

    public function __invoke(mixed ...$args): string
    {
        return $this->jsonResult;
    }
}
