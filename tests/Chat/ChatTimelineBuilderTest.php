<?php

declare(strict_types=1);

namespace App\Tests;

use App\Chat\ChatFileHistory;
use App\Chat\ChatTimelineBuilder;
use PHPUnit\Framework\TestCase;

final class ChatTimelineBuilderTest extends TestCase
{
    public function testBuildInterleavesToolsWithMessages(): void
    {
        $source = dirname(__DIR__, 2) . '/storage/neuron/ai-sessions/neuron_13.chat';
        if (!is_readable($source)) {
            self::markTestSkipped('Session 13 chat history is unavailable.');
        }

        $dir = sys_get_temp_dir() . '/web-ssh-timeline-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy($source, $dir . '/neuron_99.chat');

        try {
            $history = new ChatFileHistory($dir, '99');
            $timeline = ChatTimelineBuilder::build(
                $history,
                static fn (string $name): string => $name,
                static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

            self::assertNotEmpty($timeline);
            self::assertContains('tool', array_column($timeline, 'kind'));
            self::assertContains('message', array_column($timeline, 'kind'));

            $firstTool = null;
            foreach ($timeline as $item) {
                if (($item['kind'] ?? '') === 'tool') {
                    $firstTool = $item;
                    break;
                }
            }

            self::assertNotNull($firstTool);
            self::assertSame('list_hosts', $firstTool['name']);
            self::assertSame('done', $firstTool['status']);
        } finally {
            unlink($dir . '/neuron_99.chat');
            rmdir($dir);
        }
    }
}
