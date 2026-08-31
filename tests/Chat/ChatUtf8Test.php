<?php

declare(strict_types=1);

namespace App\Tests;

use App\Chat\ChatTimelineBuilder;
use App\Chat\ChatUtf8;
use App\Chat\ChatFileHistory;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class ChatUtf8Test extends TestCase
{
    public function testToolResultSanitizesInvalidUtf8(): void
    {
        $invalid = "chat \xE5\xAE\xB9\xe5\x99\xa8 output \xFF\xFE tail";
        $sanitized = ChatUtf8::toolResult($invalid);

        self::assertIsString($sanitized);
        self::assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
        self::assertNotFalse(json_encode(['result' => $sanitized], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testToolResultTruncatesWithoutSplittingMultibyteCharacters(): void
    {
        $text = str_repeat('中文', 2500);
        $result = ChatUtf8::toolResult($text);

        self::assertIsString($result);
        self::assertLessThanOrEqual(4004, strlen($result));
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
        self::assertNotFalse(json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testTimelineJsonEncodesToolResultsWithInvalidUtf8(): void
    {
        $dir = sys_get_temp_dir() . '/web-ssh-utf8-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $history = new ChatFileHistory($dir, '22');
            $history->addMessage(new UserMessage('hello'));

            $raw = file_get_contents(dirname(__DIR__, 2) . '/storage/neuron/ai-sessions/2026/08/31/neuron_22.chat');
            self::assertIsString($raw);
            copy(dirname(__DIR__, 2) . '/storage/neuron/ai-sessions/2026/08/31/neuron_22.chat', $dir . '/neuron_22.chat');

            $history = new ChatFileHistory($dir, '22');
            $timeline = ChatTimelineBuilder::build(
                $history,
                static fn (string $name): string => $name,
                static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

            json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            self::assertNotEmpty($timeline);
        } finally {
            @unlink($dir . '/neuron_22.chat');
            @rmdir($dir);
        }
    }
}
