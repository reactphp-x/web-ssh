<?php

declare(strict_types=1);

namespace App\Tests;

use App\Chat\AiSettingsStore;
use App\Chat\ChatFileHistory;
use App\Chat\ChatSettings;
use App\Chat\ChatTokenUsage;
use App\Config\DatabaseConfig;
use App\Security\SecretCipher;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\TestCase;
use ReactphpX\Framework\Environment;

final class ChatTokenUsageTest extends TestCase
{
    public function testEmptyHistoryReportsZeroUsage(): void
    {
        $dir = $this->tempDir();
        try {
            $history = new ChatFileHistory($dir, 'empty', 10000);
            $summary = ChatTokenUsage::summarize($history, $this->settings(10000));

            self::assertSame(0, $summary['context_used']);
            self::assertSame(10000, $summary['context_window']);
            self::assertSame(0, $summary['context_percent']);
            self::assertSame(0, $summary['cached_input_tokens']);
        } finally {
            $this->cleanup($dir, 'empty');
        }
    }

    public function testAssistantUsageContributesToContextUsed(): void
    {
        $dir = $this->tempDir();
        try {
            $history = new ChatFileHistory($dir, 'used', 10000);
            $assistant = new AssistantMessage('done');
            $assistant->setUsage(new Usage(2500, 500));
            $history->addMessage(new UserMessage('hello'));
            $history->addMessage($assistant);

            $summary = ChatTokenUsage::summarize($history, $this->settings(10000));

            self::assertGreaterThan(0, $summary['context_used']);
            self::assertSame(30, $summary['context_percent']);
        } finally {
            $this->cleanup($dir, 'used');
        }
    }

    public function testCachedInputTokensReadFromRawFileWhenDeserializedUsageOmitsCache(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/neuron_cache.chat';

        try {
            file_put_contents($file, json_encode([
                [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'content' => 'hello', 'meta' => []]],
                ],
                [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'content' => 'ok', 'meta' => []]],
                    'usage' => [
                        'input_tokens' => 1200,
                        'output_tokens' => 80,
                        'cached_input_tokens' => 8200,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            $history = new ChatFileHistory($dir, 'cache', 10000);
            $summary = ChatTokenUsage::summarize($history, $this->settings(10000));

            self::assertSame(8200, $summary['cached_input_tokens']);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testInMemoryCachedInputTokensPreferredWhenPresent(): void
    {
        $dir = $this->tempDir();
        try {
            $history = new ChatFileHistory($dir, 'mem', 10000);
            $assistant = new AssistantMessage('ok');
            $assistant->setUsage(new Usage(1000, 100, 512));
            $history->addMessage(new UserMessage('hello'));
            $history->addMessage($assistant);

            $summary = ChatTokenUsage::summarize($history, $this->settings(10000));

            self::assertSame(512, $summary['cached_input_tokens']);
        } finally {
            $this->cleanup($dir, 'mem');
        }
    }


    public function testEmitIfInferenceFinishedOnlyOnToolCallPhase(): void
    {
        $dir = $this->tempDir();
        $events = [];
        $emit = static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        };

        try {
            $history = new ChatFileHistory($dir, 'emit', 10000);
            $assistant = new AssistantMessage('tool');
            $assistant->setUsage(new Usage(1000, 50));
            $history->addMessage(new UserMessage('hello'));
            $history->addMessage($assistant);
            $settings = $this->settings(10000);

            ChatTokenUsage::emitIfInferenceFinished($history, $settings, $emit, 'tool', ['phase' => 'result']);
            self::assertSame([], $events);

            ChatTokenUsage::emitIfInferenceFinished($history, $settings, $emit, 'tool', ['phase' => 'call']);
            self::assertCount(1, $events);
            self::assertSame('usage', $events[0][0]);
            self::assertSame(1050, $events[0][1]['context_used']);
        } finally {
            $this->cleanup($dir, 'emit');
        }
    }

    private function settings(int $contextWindow): ChatSettings
    {
        $root = dirname(__DIR__, 2);
        $environment = Environment::load($root, '.env');
        $dbConfig = DatabaseConfig::load($environment);
        $cipher = SecretCipher::fromAppKey($environment->string('APP_KEY'));
        $store = AiSettingsStore::ephemeral($dbConfig, $cipher, [
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'context_window' => $contextWindow,
        ], [
            'api_key' => 'test-key',
        ]);

        return new ChatSettings($environment, $store);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/web-ssh-token-' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    private function cleanup(string $dir, string $key): void
    {
        @unlink($dir . '/neuron_' . $key . '.chat');
        @rmdir($dir);
    }
}
