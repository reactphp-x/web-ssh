<?php

declare(strict_types=1);

namespace App\Policy;

use App\Policy\Ast\ScriptWalker;
use function ReactphpX\Unbash\parse;

/**
 * Expands invocations found inside shell -c / --command script arguments.
 */
final class NestedScriptExpander
{
    /**
     * @param list<CommandInvocation> $invocations
     * @return list<CommandInvocation>
     */
    public function expand(array $invocations): array
    {
        $expanded = $invocations;

        foreach ($invocations as $invocation) {
            foreach ($this->extractCommandScripts($invocation->args) as $script) {
                foreach ($this->parseScriptInvocations($script) as $nested) {
                    $expanded[] = new CommandInvocation(
                        $nested['binary'],
                        $nested['args'],
                        $invocation->pipelineIndex,
                    );
                }
            }
        }

        return $expanded;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function extractCommandScripts(array $args): array
    {
        $scripts = [];
        $count = count($args);

        for ($i = 0; $i < $count; ++$i) {
            if ($args[$i] !== '-c' && $args[$i] !== '--command') {
                continue;
            }

            $script = $args[$i + 1] ?? '';
            if ($script !== '') {
                $scripts[] = $script;
            }
        }

        return $scripts;
    }

    /**
     * @return list<array{binary: string, args: list<string>}>
     */
    private function parseScriptInvocations(string $script): array
    {
        $script = trim($script);
        if ($script === '') {
            return [];
        }

        $parsed = parse($script);
        $walker = new ScriptWalker();
        $walker->walk($parsed, []);

        $invocations = [];
        foreach ($walker->invocations() as $item) {
            $invocations[] = [
                'binary' => $item['binary'],
                'args' => $item['args'],
            ];
        }

        return $invocations;
    }
}
