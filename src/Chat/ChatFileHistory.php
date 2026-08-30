<?php

declare(strict_types=1);

namespace App\Chat;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

/**
 * File history that drops incomplete tool turns left by aborted SSE streams.
 */
final class ChatFileHistory extends FileChatHistory
{
    public function repairIncompleteToolCalls(bool $dropIncompleteTurn = true): void
    {
        $original = $this->getMessages();
        $repaired = $this->withoutIncompleteToolCalls($original);
        if ($dropIncompleteTurn) {
            $repaired = $this->withoutIncompleteAssistantTurn($repaired);
        }
        if ($original === $repaired) {
            return;
        }

        $this->history = $repaired;
        $this->updateFile();
    }

    /**
     * @param list<object> $messages
     * @return list<object>
     */
    private function withoutIncompleteToolCalls(array $messages): array
    {
        $out = [];
        $count = count($messages);
        for ($i = 0; $i < $count; $i++) {
            $message = $messages[$i];
            if (!$message instanceof ToolCallMessage) {
                $out[] = $message;
                continue;
            }
            $next = $messages[$i + 1] ?? null;
            if ($next instanceof ToolResultMessage) {
                $out[] = $message;
                $out[] = $next;
                $i++;
            }
        }

        return $out;
    }

    /**
     * @param list<object> $messages
     * @return list<object>
     */
    private function withoutIncompleteAssistantTurn(array $messages): array
    {
        if ($messages === []) {
            return $messages;
        }
        $last = $messages[array_key_last($messages)] ?? null;
        if ($last instanceof ToolCallMessage || $last instanceof ToolResultMessage) {
            array_pop($messages);
            $prev = $messages[array_key_last($messages)] ?? null;
            if ($prev instanceof ToolCallMessage) {
                array_pop($messages);
            }
        }
        $last = $messages[array_key_last($messages)] ?? null;
        if (is_object($last) && method_exists($last, 'getRole') && $last->getRole() === MessageRole::USER->value) {
            array_pop($messages);
        }

        return $messages;
    }
}
