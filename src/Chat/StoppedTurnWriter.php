<?php

declare(strict_types=1);

namespace App\Chat;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Persists partially generated assistant turns after manual stop.
 */
final class StoppedTurnWriter
{
    public function saveManualStop(
        ChatFileHistory $history,
        string $userMessage,
        string $assembled,
        bool $persistTurn,
    ): string {
        $content = trim($assembled);
        if ($persistTurn && $content !== '') {
            $this->persistStoppedTurn($history, $userMessage, $content);
        }

        return $content;
    }

    private function persistStoppedTurn(
        ChatFileHistory $history,
        string $userMessage,
        string $content,
    ): void {
        $content = trim($content);
        $userMessage = trim($userMessage);

        $messages = $history->getMessages();
        $last = $messages !== [] ? $messages[array_key_last($messages)] : null;

        if ($last instanceof AssistantMessage && !StoppedMessageMetadata::isStopped($last->getMetadata('stopped'))) {
            $prev = count($messages) >= 2 ? $messages[count($messages) - 2] : null;
            $currentUserCommitted = $prev instanceof UserMessage
                && trim((string) $prev->getContent()) === $userMessage;

            if ($currentUserCommitted) {
                $history->popLastMessage();
                $this->appendStoppedAssistant($history, $content);

                return;
            }
        }

        if ($last instanceof UserMessage) {
            $this->appendStoppedAssistant($history, $content);

            return;
        }

        if ($userMessage !== '') {
            $history->addMessage(new UserMessage($userMessage));
        }
        $this->appendStoppedAssistant($history, $content);
    }

    private function appendStoppedAssistant(ChatFileHistory $history, string $content): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }

        $messages = $history->getMessages();
        $last = $messages !== [] ? $messages[array_key_last($messages)] : null;
        if (!$last instanceof UserMessage) {
            throw new ChatException('无法保存停止的回复：会话历史不完整');
        }

        $assistant = new AssistantMessage($content);
        $assistant->addMetadata('stopped', StoppedMessageMetadata::STOPPED);
        $history->addMessage($assistant);
    }
}
