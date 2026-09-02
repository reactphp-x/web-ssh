<?php

declare(strict_types=1);

namespace App\Policy\Ast;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

final class ScriptWalker
{
    /** @var list<array{binary: string, args: list<string>, pipeline_index: int}> */
    private array $invocations = [];

    /** @var list<array{operator: string, target: string}> */
    private array $redirects = [];

    private bool $hasCommandSubstitution = false;

    private bool $hasProcessSubstitution = false;

    private bool $isBackground = false;

    private bool $hasCompoundCommand = false;

    /** @var list<string> */
    private array $parseErrors = [];

    private int $pipelineIndex = 0;

    /** @var list<string> */
    private const WRAPPER_BINARIES = ['sudo', 'env', 'command', 'nohup', 'time', 'nice', 'stdbuf'];

    /**
     * @param list<string> $parseErrors
     */
    public function walk(Node $script, array $parseErrors = []): void
    {
        foreach ($parseErrors as $error) {
            if (is_string($error)) {
                $this->parseErrors[] = $error;
            } elseif (is_object($error) && isset($error->message)) {
                $this->parseErrors[] = (string) $error->message;
            }
        }

        if ($script->errors !== null && is_array($script->errors)) {
            foreach ($script->errors as $error) {
                if (is_object($error) && isset($error->message)) {
                    $this->parseErrors[] = (string) $error->message;
                }
            }
        }

        if (!is_array($script->commands)) {
            return;
        }

        foreach ($script->commands as $command) {
            if ($command instanceof Node) {
                $this->walkStatement($command);
            }
        }
    }

    /**
     * @return list<array{binary: string, args: list<string>, pipeline_index: int}>
     */
    public function invocations(): array
    {
        return $this->invocations;
    }

    /**
     * @return list<array{operator: string, target: string}>
     */
    public function redirects(): array
    {
        return $this->redirects;
    }

    public function hasCommandSubstitution(): bool
    {
        return $this->hasCommandSubstitution;
    }

    public function hasProcessSubstitution(): bool
    {
        return $this->hasProcessSubstitution;
    }

    public function isBackground(): bool
    {
        return $this->isBackground;
    }

    public function hasCompoundCommand(): bool
    {
        return $this->hasCompoundCommand;
    }

    /**
     * @return list<string>
     */
    public function parseErrors(): array
    {
        return $this->parseErrors;
    }

    private function walkStatement(Node $statement): void
    {
        if ($statement->background === true) {
            $this->isBackground = true;
        }

        if (is_array($statement->redirects)) {
            foreach ($statement->redirects as $redirect) {
                if ($redirect instanceof Node) {
                    $this->collectRedirect($redirect);
                }
            }
        }

        $command = $statement->command ?? null;
        if (!$command instanceof Node) {
            return;
        }

        $this->walkCommandNode($command);
    }

    private function walkCommandNode(Node $command): void
    {
        match ($command->type) {
            'Command' => $this->collectCommand($command, $this->pipelineIndex),
            'Pipeline' => $this->walkPipeline($command),
            'AndOr' => $this->walkAndOr($command),
            'If', 'For', 'While', 'Until', 'Case', 'Select', 'Subshell', 'BraceGroup', 'FunctionDef', 'Coproc', 'ArithmeticCommand' => $this->walkCompound($command),
            'AndOrList', 'CompoundList' => $this->walkNestedCommand($command),
            default => null,
        };
    }

    private function walkAndOr(Node $andOr): void
    {
        $this->hasCompoundCommand = true;

        if (!is_array($andOr->commands)) {
            return;
        }

        foreach ($andOr->commands as $stage) {
            if ($stage instanceof Node) {
                $this->walkCommandNode($stage);
            }
        }
    }

    private function walkPipeline(Node $pipeline): void
    {
        if (!is_array($pipeline->commands)) {
            return;
        }

        $index = $this->pipelineIndex;
        foreach ($pipeline->commands as $stage) {
            if ($stage instanceof Node && $stage->type === 'Command') {
                $this->collectCommand($stage, $index);
            } elseif ($stage instanceof Node) {
                $this->walkCommandNode($stage);
            }
        }
    }

    private function walkCompound(Node $compound): void
    {
        $this->hasCompoundCommand = true;

        foreach ($compound->properties() as $value) {
            $this->walkCompoundValue($value);
        }
    }

    private function walkCompoundValue(mixed $value): void
    {
        if ($value instanceof Node) {
            if ($value->type === 'CompoundList' && is_array($value->commands)) {
                foreach ($value->commands as $statement) {
                    if ($statement instanceof Node) {
                        $this->walkStatement($statement);
                    }
                }

                return;
            }

            if ($value->type === 'Script') {
                $this->walk($value, []);

                return;
            }

            $this->walkNestedCommand($value);

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->walkCompoundValue($item);
        }
    }

    private function walkNestedCommand(Node $command): void
    {
        if ($command->type === 'AndOrList' || $command->type === 'CompoundList' || $command->type === 'AndOr') {
            $this->hasCompoundCommand = true;
            if ($command->type === 'AndOr') {
                $this->walkAndOr($command);

                return;
            }
            $this->walkCompoundValue($command);

            return;
        }

        if ($command->type === 'Command') {
            $this->collectCommand($command, $this->pipelineIndex);
        }
    }

    private function markCompound(): void
    {
        $this->hasCompoundCommand = true;
    }

    private function collectCommand(Node $command, int $pipelineIndex): void
    {
        $binary = $this->wordText($command->name ?? null);
        if ($binary === '') {
            return;
        }

        $args = [];
        if (is_array($command->suffix)) {
            foreach ($command->suffix as $word) {
                $args[] = $this->wordText($word);
                $this->scanWordParts($word);
            }
        }

        if (is_array($command->prefix)) {
            foreach ($command->prefix as $assignment) {
                if ($assignment instanceof Node && is_array($assignment->suffix ?? null)) {
                    foreach ($assignment->suffix as $word) {
                        $this->scanWordParts($word);
                    }
                }
            }
        }

        if (is_array($command->redirects)) {
            foreach ($command->redirects as $redirect) {
                if ($redirect instanceof Node) {
                    $this->collectRedirect($redirect);
                }
            }
        }

        $this->invocations[] = [
            'binary' => $binary,
            'args' => $args,
            'pipeline_index' => $pipelineIndex,
        ];

        $wrapped = $this->extractWrappedBinary($binary, $args);
        if ($wrapped !== null) {
            $this->invocations[] = [
                'binary' => $wrapped,
                'args' => [],
                'pipeline_index' => $pipelineIndex,
            ];
        }
    }

    /**
     * @param list<string> $args
     */
    private function extractWrappedBinary(string $binary, array $args): ?string
    {
        if (!in_array($this->normalizeBinary($binary), self::WRAPPER_BINARIES, true)) {
            return null;
        }

        foreach ($args as $arg) {
            if ($arg === '' || str_starts_with($arg, '-')) {
                continue;
            }

            if ($this->looksLikeAssignment($arg)) {
                continue;
            }

            return $arg;
        }

        return null;
    }

    private function looksLikeAssignment(string $arg): bool
    {
        if (!str_contains($arg, '=')) {
            return false;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $arg) === 1;
    }

    private function normalizeBinary(string $binary): string
    {
        return strtolower(basename(str_replace('\\', '/', trim($binary))));
    }

    private function collectRedirect(Node $redirect): void
    {
        $operator = is_string($redirect->operator ?? null) ? $redirect->operator : '';
        $target = $this->wordText($redirect->target ?? null);
        if ($operator === '' && $target === '') {
            return;
        }

        $this->redirects[] = [
            'operator' => $operator,
            'target' => $target,
        ];
    }

    private function scanWordParts(mixed $word): void
    {
        if (!$word instanceof Word && !is_object($word)) {
            return;
        }

        $parts = $word->parts ?? null;
        if (!is_array($parts)) {
            return;
        }

        foreach ($parts as $part) {
            if (!$part instanceof Node) {
                continue;
            }

            match ($part->type) {
                'CommandExpansion' => $this->walkCommandExpansion($part),
                'ProcessSubstitution' => $this->hasProcessSubstitution = true,
                default => null,
            };
        }
    }

    private function walkCommandExpansion(Node $part): void
    {
        $this->hasCommandSubstitution = true;
        $nested = $part->script ?? null;
        if ($nested instanceof Node) {
            $nestedWalker = new self();
            $nestedWalker->walk($nested, []);
            $this->mergeFrom($nestedWalker);
        }
    }

    private function mergeFrom(self $other): void
    {
        foreach ($other->invocations() as $invocation) {
            $this->invocations[] = $invocation;
        }
        foreach ($other->redirects() as $redirect) {
            $this->redirects[] = $redirect;
        }
        $this->hasCommandSubstitution = $this->hasCommandSubstitution || $other->hasCommandSubstitution();
        $this->hasProcessSubstitution = $this->hasProcessSubstitution || $other->hasProcessSubstitution();
        $this->isBackground = $this->isBackground || $other->isBackground();
        $this->hasCompoundCommand = $this->hasCompoundCommand || $other->hasCompoundCommand();
        foreach ($other->parseErrors() as $error) {
            $this->parseErrors[] = $error;
        }
    }

    private function wordText(mixed $word): string
    {
        if ($word instanceof Word) {
            return trim((string) ($word->value ?? $word->text ?? ''));
        }

        if ($word instanceof Node && isset($word->value)) {
            return trim((string) $word->value);
        }

        if (is_object($word) && isset($word->value)) {
            return trim((string) $word->value);
        }

        return '';
    }
}
