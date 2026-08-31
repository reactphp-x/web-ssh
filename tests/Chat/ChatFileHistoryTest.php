<?php

declare(strict_types=1);

namespace App\Tests;

use App\Chat\ChatFileHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class ChatFileHistoryTest extends TestCase
{
    public function testAddMessageMergesConsecutiveUserMessages(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-history-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '18');
            $history->addMessage(new UserMessage("## Previous conversation summary:\n\nEarlier context."));
            $history->addMessage(new UserMessage('继续查 prod-qingyuepai 磁盘'));

            $messages = $history->getMessages();
            self::assertCount(1, $messages);
            self::assertInstanceOf(UserMessage::class, $messages[0]);
            self::assertStringContainsString('Earlier context.', (string) $messages[0]->getContent());
            self::assertStringContainsString('继续查 prod-qingyuepai 磁盘', (string) $messages[0]->getContent());
        } finally {
            @unlink($dir . '/neuron_18.chat');
            @rmdir($dir);
        }
    }

    public function testRepairMergesPersistedConsecutiveUserMessages(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-history-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/neuron_19.chat';

        try {
            file_put_contents($file, json_encode([
                [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'content' => 'summary user', 'meta' => []]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'content' => 'second user', 'meta' => []]],
                ],
            ], JSON_THROW_ON_ERROR));

            $history = new ChatFileHistory($dir, '19');
            $history->repairIncompleteToolCalls(false);

            $messages = $history->getMessages();
            self::assertCount(1, $messages);
            self::assertSame("summary user\n\nsecond user", $messages[0]->getContent());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testRepairKeepsConversationSummaryAsPendingContext(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-history-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '20');
            $history->addMessage(new UserMessage("## Previous conversation summary:\n\nContext only."));

            $history->repairIncompleteToolCalls(true);

            $messages = $history->getMessages();
            self::assertCount(1, $messages);
            self::assertInstanceOf(UserMessage::class, $messages[0]);
        } finally {
            @unlink($dir . '/neuron_20.chat');
            @rmdir($dir);
        }
    }

    public function testRepairStillDropsUnansweredUserTurn(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-history-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '21');
            $history->addMessage(new UserMessage('hello'));
            $history->addMessage(new AssistantMessage('hi there'));

            $history->addMessage(new UserMessage('pending question'));
            $history->repairIncompleteToolCalls(true);

            $messages = $history->getMessages();
            self::assertCount(2, $messages);
            self::assertInstanceOf(UserMessage::class, $messages[0]);
            self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        } finally {
            @unlink($dir . '/neuron_21.chat');
            @rmdir($dir);
        }
    }
}
