<?php

declare(strict_types=1);

namespace App\Tests\Neuron;

use App\Chat\AiSettingsStore;
use App\Chat\ChatSettings;
use App\Config\DatabaseConfig;
use App\Neuron\AiProviderFactory;
use App\Security\SecretCipher;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;
use PHPUnit\Framework\TestCase;
use ReactphpX\Framework\Environment;

final class AiProviderFactoryTest extends TestCase
{
    public function testOpenAiProvider(): void
    {
        $settings = $this->settings([
            'provider' => 'openai',
            'api_key' => 'test-key',
            'model' => 'gpt-4o-mini',
        ]);

        $provider = AiProviderFactory::create($settings);

        self::assertInstanceOf(OpenAI::class, $provider);
    }

    public function testDeepseekProvider(): void
    {
        $settings = $this->settings([
            'provider' => 'deepseek',
            'api_key' => 'test-key',
            'model' => 'deepseek-chat',
        ]);

        $provider = AiProviderFactory::create($settings);

        self::assertInstanceOf(Deepseek::class, $provider);
    }

    public function testUnknownProviderUsesOpenAiLikeWhenBaseUrlSet(): void
    {
        $settings = $this->settings([
            'provider' => 'kimi',
            'api_key' => 'test-key',
            'model' => 'kimi-k2.6',
            'base_url' => 'https://api.moonshot.cn/v1',
        ]);

        $provider = AiProviderFactory::create($settings);

        self::assertInstanceOf(OpenAILike::class, $provider);
    }

    public function testOpenAiLikeRequiresBaseUrl(): void
    {
        $settings = $this->settings([
            'provider' => 'openailike',
            'api_key' => 'test-key',
            'model' => 'custom-model',
            'base_url' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Base URL');

        AiProviderFactory::create($settings);
    }

    /**
     * @param array<string, string> $values
     */
    private function settings(array $values): ChatSettings
    {
        $root = dirname(__DIR__, 2);
        $environment = Environment::load($root, '.env');
        $dbConfig = DatabaseConfig::load($environment);
        $cipher = SecretCipher::fromAppKey($environment->string('APP_KEY'));

        $config = [
            'enabled' => true,
            'provider' => $values['provider'] ?? 'openai',
            'model' => $values['model'] ?? 'gpt-4o-mini',
            'base_url' => $values['base_url'] ?? '',
        ];
        $secrets = [
            'api_key' => $values['api_key'] ?? '',
        ];

        $store = AiSettingsStore::ephemeral($dbConfig, $cipher, $config, $secrets);

        return new ChatSettings($environment, $store);
    }
}
