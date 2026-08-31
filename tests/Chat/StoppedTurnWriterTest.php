<?php

declare(strict_types=1);

namespace App\Tests;

use App\Chat\ChatFileHistory;
use App\Chat\StoppedMessageMetadata;
use App\Chat\StoppedTurnWriter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class StoppedTurnWriterTest extends TestCase
{
    public function testSaveManualStopPersistsPartialAssistantTurn(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-stopped-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '42');
            $history->addMessage(new UserMessage('run uptime'));
            $writer = new StoppedTurnWriter();

            $content = $writer->saveManualStop($history, 'run uptime', 'Checking hosts...', true);

            self::assertSame('Checking hosts...', $content);

            $messages = $history->getMessages();
            self::assertCount(2, $messages);
            self::assertInstanceOf(UserMessage::class, $messages[0]);
            self::assertInstanceOf(AssistantMessage::class, $messages[1]);
            self::assertSame('Checking hosts...', $messages[1]->getContent());
            self::assertTrue(
                StoppedMessageMetadata::isStopped($messages[1]->getMetadata('stopped')),
            );
        } finally {
            @unlink($dir . '/neuron_42.chat');
            @rmdir($dir);
        }
    }

    public function testSaveManualStopSkipsEmptyContent(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-stopped-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '43');
            $writer = new StoppedTurnWriter();

            $content = $writer->saveManualStop($history, 'hello', '   ', true);

            self::assertSame('', $content);
            self::assertSame([], $history->getMessages());
        } finally {
            @unlink($dir . '/neuron_43.chat');
            @rmdir($dir);
        }
    }
}
