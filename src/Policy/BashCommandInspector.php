<?php

declare(strict_types=1);

namespace App\Policy;

use App\Policy\Ast\ScriptWalker;
use function ReactphpX\Unbash\parse;

final class BashCommandInspector
{
    public function inspect(string $command): CommandInspection
    {
        $command = trim($command);
        if ($command === '') {
            return new CommandInspection(
                invocations: [],
                redirects: [],
                hasCommandSubstitution: false,
                hasProcessSubstitution: false,
                isBackground: false,
                hasCompoundCommand: false,
                unparseable: true,
                parseErrors: ['empty command'],
            );
        }

        $script = parse($command);
        $walker = new ScriptWalker();
        $parseErrors = [];
        if ($script->errors !== null && is_array($script->errors)) {
            foreach ($script->errors as $error) {
                if (is_object($error) && isset($error->message)) {
                    $parseErrors[] = (string) $error->message;
                }
            }
        }

        $walker->walk($script, $parseErrors);

        $invocations = [];
        foreach ($walker->invocations() as $item) {
            $invocations[] = new CommandInvocation(
                $item['binary'],
                $item['args'],
                $item['pipeline_index'],
            );
        }

        $expander = new NestedScriptExpander();
        $invocations = $expander->expand($invocations);

        $redirects = [];
        foreach ($walker->redirects() as $item) {
            $redirects[] = new CommandRedirect($item['operator'], $item['target']);
        }

        $errors = $walker->parseErrors();
        $unparseable = $errors !== [] && $invocations === [];

        return new CommandInspection(
            invocations: $invocations,
            redirects: $redirects,
            hasCommandSubstitution: $walker->hasCommandSubstitution(),
            hasProcessSubstitution: $walker->hasProcessSubstitution(),
            isBackground: $walker->isBackground(),
            hasCompoundCommand: $walker->hasCompoundCommand(),
            unparseable: $unparseable,
            parseErrors: $errors,
        );
    }
}
