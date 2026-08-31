<?php

declare(strict_types=1);

namespace App\Tests;

use App\Chat\ChatFileHistory;
use App\Chat\ChatStreamSession;
use App\Chat\StoppedMessageMetadata;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class ChatStreamSessionSubscribeTest extends TestCase
{
    public function testEventsSinceReplaysBufferedGenerationEvents(): void
    {
        $session = new ChatStreamSession();
        $key = 'test-session-key';

        $session->begin($key, '15', 'hello');
        $session->append($key, 'delta', ['text' => 'Hi']);
        $session->append($key, 'done', ['content' => 'Hi', 'stopped' => false]);

        $events = $session->eventsSince($key, 0);
        self::assertCount(2, $events);
        self::assertSame('delta', $events[0]['event']);
        self::assertSame('done', $events[1]['event']);

        $tail = $session->eventsSince($key, 1);
        self::assertCount(1, $tail);
        self::assertSame('done', $tail[0]['event']);
    }

    public function testFinishPreservesBufferedEventsForSubscribe(): void
    {
        $session = new ChatStreamSession();
        $key = 'finish-preserve-key';

        $session->begin($key, '15', 'hello');
        $session->append($key, 'delta', ['text' => 'Hi']);
        $session->append($key, 'done', ['content' => 'Hi', 'stopped' => false]);
        $session->finish($key);

        self::assertNull($session->getMeta($key));
        self::assertTrue($session->isSubscribeAllowed($key));
        self::assertTrue($session->isStreamComplete($key));

        $events = $session->eventsSince($key, 0);
        self::assertCount(2, $events);
        self::assertSame('done', $events[1]['event']);
    }

    public function testBeginClearsPreviousBufferedEvents(): void
    {
        $session = new ChatStreamSession();
        $key = 'begin-clear-key';

        $session->begin($key, '15', 'hello');
        $session->append($key, 'delta', ['text' => 'old']);
        $session->finish($key);

        $session->begin($key, '15', 'next');
        self::assertSame([], $session->eventsSince($key, 0));
    }

    public function testGetMetaExposesActiveGeneration(): void
    {
        $session = new ChatStreamSession();
        $key = 'active-key';

        $session->begin($key, '15', 'hello');
        $session->append($key, 'delta', ['text' => 'partial']);

        $meta = $session->getMeta($key);
        self::assertNotNull($meta);
        self::assertTrue($meta['active']);
        self::assertSame('partial', $meta['partial']);
        self::assertSame(1, $meta['events_count']);
    }

    public function testTimelineBuilderMarksStoppedAssistantMessages(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-stopped-timeline-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '44');
            $history->addMessage(new UserMessage('hello'));
            $assistant = new AssistantMessage('partial reply');
            $assistant->addMetadata('stopped', StoppedMessageMetadata::STOPPED);
            $history->addMessage($assistant);

            $timeline = \App\Chat\ChatTimelineBuilder::build(
                $history,
                static fn (string $name): string => $name,
                static fn (string $text): string => $text,
            );

            self::assertCount(2, $timeline);
            self::assertTrue($timeline[1]['stopped'] ?? false);
        } finally {
            @unlink($dir . '/neuron_44.chat');
            @rmdir($dir);
        }
    }
}
