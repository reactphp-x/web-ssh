<?php

declare(strict_types=1);

namespace App\Policy;

final class CommandPolicyEngine
{
    /** @var list<list<string>> */
    private const BUILTIN_APPROVAL_PIPELINES = [
        ['curl', 'bash'],
        ['curl', 'sh'],
        ['wget', 'bash'],
        ['wget', 'sh'],
    ];

    public function __construct(
        private readonly BashCommandInspector $inspector,
        private readonly PolicyRuleLoader $rules,
    ) {
    }

    public function evaluate(string $command, PolicyContext $context): PolicyDecision
    {
        $inspection = $this->inspector->inspect($command);
        $config = $this->rules->load($context);

        if ($inspection->unparseable) {
            return new PolicyDecision(
                PolicyAction::RequireApproval,
                '命令无法完整解析，需人工审批后执行。',
                'unparseable',
                $inspection,
            );
        }

        if ($this->matchesDenyRules($inspection, $config)) {
            [$rule, $reason] = $this->denyReason($inspection, $config);

            return new PolicyDecision(
                PolicyAction::Deny,
                $reason,
                $rule,
                $inspection,
            );
        }

        if ($this->requiresApproval($inspection, $config)) {
            return new PolicyDecision(
                PolicyAction::RequireApproval,
                $this->approvalReason($inspection, $config),
                $this->approvalMatchedRule($inspection, $config),
                $inspection,
            );
        }

        return new PolicyDecision(
            PolicyAction::AutoRun,
            '未命中拒绝或审批规则，已自动批准执行。',
            'auto_run',
            $inspection,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function matchesDenyRules(CommandInspection $inspection, array $config): bool
    {
        return $this->denyReason($inspection, $config)[0] !== null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: ?string, 1: string}
     */
    private function denyReason(CommandInspection $inspection, array $config): array
    {
        $denyBinaries = $this->stringSet($config['deny_binaries'] ?? []);

        foreach ($inspection->invocations as $invocation) {
            if ($this->matchesBinarySet($invocation, $denyBinaries)) {
                return ['deny_binary', sprintf('禁止通过 AI 执行：%s', $invocation->binary)];
            }
        }

        return [null, ''];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requiresApproval(CommandInspection $inspection, array $config): bool
    {
        return $this->approvalMatchedRule($inspection, $config) !== null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function approvalReason(CommandInspection $inspection, array $config): string
    {
        return match ($this->approvalMatchedRule($inspection, $config)) {
            'approval_pipeline' => '通过管道将远程内容交给 shell 执行，需人工审批。',
            default => '该命令需人工审批后执行（会话自动批准无效）。',
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function approvalMatchedRule(CommandInspection $inspection, array $config): ?string
    {
        $approvalBinaries = $this->stringSet($config['require_approval_binaries'] ?? []);

        foreach ($inspection->invocations as $invocation) {
            if ($this->matchesBinarySet($invocation, $approvalBinaries)) {
                return 'require_approval';
            }
        }

        if ($this->matchesApprovalPipeline($inspection)) {
            return 'approval_pipeline';
        }

        return null;
    }

    private function matchesApprovalPipeline(CommandInspection $inspection): bool
    {
        $byPipeline = [];
        foreach ($inspection->invocations as $invocation) {
            $byPipeline[$invocation->pipelineIndex][] = $this->normalizeBinary($invocation->binary);
        }

        foreach ($byPipeline as $binaries) {
            foreach (self::BUILTIN_APPROVAL_PIPELINES as $pair) {
                $left = $this->normalizeBinary($pair[0]);
                $right = $this->normalizeBinary($pair[1]);
                for ($i = 0; $i < count($binaries) - 1; ++$i) {
                    if ($binaries[$i] === $left && $binaries[$i + 1] === $right) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $binaries
     */
    private function matchesBinarySet(CommandInvocation $invocation, array $binaries): bool
    {
        if (isset($binaries[$this->normalizeBinary($invocation->binary)])) {
            return true;
        }

        foreach ($invocation->args as $arg) {
            if ($arg === '' || str_starts_with($arg, '-')) {
                continue;
            }

            if (str_contains($arg, '=') && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $arg) === 1) {
                continue;
            }

            if (isset($binaries[$this->normalizeBinary($arg)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $values
     * @return array<string, true>
     */
    private function stringSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $set[$this->normalizeBinary($value)] = true;
        }

        return $set;
    }

    private function normalizeBinary(string $binary): string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return '';
        }

        $base = basename(str_replace('\\', '/', $binary));

        return strtolower($base);
    }
}
