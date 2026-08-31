<?php

declare(strict_types=1);

namespace App\Chat;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * File history that drops incomplete tool turns left by aborted SSE streams.
 */
final class ChatFileHistory extends FileChatHistory
{
    private const SUMMARY_PREFIX = '## Previous conversation summary:';

    public function addMessage(Message $message): ChatHistoryInterface
    {
        if ($message instanceof UserMessage) {
            $messages = $this->getMessages();
            $last = $messages !== [] ? $messages[array_key_last($messages)] : null;
            if ($last instanceof UserMessage) {
                $message = new UserMessage(trim($last->getContent() . "\n\n" . $message->getContent()));
                array_pop($this->history);
            }
        }

        return parent::addMessage($message);
    }

    public function repairIncompleteToolCalls(bool $dropIncompleteTurn = true): void
    {
        $original = $this->getMessages();
        $repaired = $this->withoutIncompleteToolCalls($original);
        $repaired = $this->mergeConsecutiveUserMessages($repaired);
        if ($dropIncompleteTurn) {
            $repaired = $this->withoutIncompleteAssistantTurn($repaired);
        }
        if ($original === $repaired) {
            return;
        }

        $this->history = $repaired;
        $this->updateFile();
    }

    public function popLastMessage(): void
    {
        if ($this->history === []) {
            return;
        }

        array_pop($this->history);
        $this->updateFile();
    }

    public function replaceLastAssistantContent(string $content): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }

        $messages = $this->getMessages();
        if ($messages === []) {
            return;
        }

        $last = $messages[array_key_last($messages)];
        if (!$last instanceof \NeuronAI\Chat\Messages\AssistantMessage) {
            return;
        }

        array_pop($this->history);
        $assistant = new \NeuronAI\Chat\Messages\AssistantMessage($content);
        $stopped = $last->getMetadata('stopped');
        if ($stopped !== null) {
            $assistant->addMetadata('stopped', $stopped);
        }
        $this->history[] = $assistant;
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
        if ($last instanceof UserMessage && !$this->isConversationSummary($last)) {
            array_pop($messages);
        }

        return $messages;
    }

    /**
     * @param list<object> $messages
     * @return list<object>
     */
    private function mergeConsecutiveUserMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if ($message instanceof UserMessage && $out !== []) {
                $last = $out[array_key_last($out)];
                if ($last instanceof UserMessage) {
                    array_pop($out);
                    $out[] = new UserMessage(trim($last->getContent() . "\n\n" . $message->getContent()));
                    continue;
                }
            }
            $out[] = $message;
        }

        return $out;
    }

    private function isConversationSummary(UserMessage $message): bool
    {
        return str_starts_with(trim((string) $message->getContent()), self::SUMMARY_PREFIX);
    }
}
