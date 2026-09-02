<?php

declare(strict_types=1);

namespace App\Policy;

final readonly class CommandInspection
{
    /**
     * @param list<CommandInvocation> $invocations
     * @param list<CommandRedirect> $redirects
     * @param list<string> $parseErrors
     */
    public function __construct(
        public array $invocations,
        public array $redirects,
        public bool $hasCommandSubstitution,
        public bool $hasProcessSubstitution,
        public bool $isBackground,
        public bool $hasCompoundCommand,
        public bool $unparseable,
        public array $parseErrors,
    ) {
    }

    /**
     * @return list<string>
     */
    public function binaries(): array
    {
        $seen = [];
        $binaries = [];
        foreach ($this->invocations as $invocation) {
            $binary = $invocation->binary;
            if ($binary === '' || isset($seen[$binary])) {
                continue;
            }
            $seen[$binary] = true;
            $binaries[] = $binary;
        }

        return $binaries;
    }

    public function summary(): string
    {
        if ($this->invocations === []) {
            return $this->unparseable ? '无法解析命令' : '空命令';
        }

        $parts = [];
        $byPipeline = [];
        foreach ($this->invocations as $invocation) {
            $byPipeline[$invocation->pipelineIndex][] = $invocation->binary;
        }
        ksort($byPipeline);
        foreach ($byPipeline as $binaries) {
            $parts[] = implode(' | ', $binaries);
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditSummary(): array
    {
        return [
            'binaries' => $this->binaries(),
            'summary' => $this->summary(),
            'has_command_substitution' => $this->hasCommandSubstitution,
            'has_process_substitution' => $this->hasProcessSubstitution,
            'is_background' => $this->isBackground,
            'has_compound_command' => $this->hasCompoundCommand,
            'unparseable' => $this->unparseable,
        ];
    }
}
