<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Chat\ChatSettings;
use App\Neuron\HttpClient\ReactHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use Throwable;

final class AiModelLister
{
    /** @var list<string> */
    private const OPENAI_COMPATIBLE = [
        'openai',
        'deepseek',
        'mistral',
        'grok',
        'xai',
        'zai',
        'dashscope',
        'alibaba',
        'cohere',
        'openai-responses',
        'responses',
        'openailike',
        'openailike-responses',
    ];

    /** @var list<string> */
    private const MANUAL_ONLY = [
        'anthropic',
        'claude',
        'bedrock',
        'aws-bedrock',
        'aws',
        'gemini-vertex',
        'gemini_vertex',
        'anthropic-vertex',
        'anthropic_vertex',
        'huggingface',
        'hf',
    ];

    /**
     * @return array{success: bool, models: list<string>, manual_only: bool, message: string}
     */
    public function list(ChatSettings $settings): array
    {
        $provider = $settings->provider();
        $http = new ReactHttpClient(timeout: min($settings->httpTimeout(), 30.0));

        try {
            if ($provider === 'ollama') {
                return $this->listOllama($settings, $http);
            }

            if (in_array($provider, ['gemini', 'google'], true)) {
                return $this->listGemini($settings, $http);
            }

            if (in_array($provider, ['azure', 'azure-openai'], true)) {
                return $this->listAzure($settings, $http);
            }

            if (in_array($provider, self::MANUAL_ONLY, true)) {
                return $this->manualOnly('该 Provider 不支持模型列表 API，请手动填写。');
            }

            if ($this->isOpenAiCompatible($provider)) {
                return $this->listOpenAiCompatible($settings, $http);
            }

            return $this->manualOnly('未知 Provider，请手动填写模型名称。');
        } catch (Throwable $e) {
            return [
                'success' => false,
                'models' => [],
                'manual_only' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, models: list<string>, manual_only: bool, message: string}
     */
    private function listOpenAiCompatible(ChatSettings $settings, ReactHttpClient $http): array
    {
        $baseUri = $this->resolveOpenAiBaseUri($settings);
        if ($baseUri === '') {
            return $this->manualOnly('请先填写 Base URL。');
        }

        $client = $http
            ->withBaseUri(rtrim($baseUri, '/'))
            ->withHeaders([
                'Authorization' => 'Bearer ' . $settings->apiKey(),
                'Accept' => 'application/json',
            ]);

        $response = $client->request(HttpRequest::get('models'));
        if ($response->statusCode >= 400) {
            throw new \RuntimeException($this->extractErrorMessage($response->body) ?: "HTTP {$response->statusCode}");
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('模型列表响应格式无效。');
        }

        $models = [];
        foreach ($payload['data'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        $models = $this->filterChatModels($models);
        sort($models);

        if ($models === []) {
            return $this->manualOnly('未获取到可用模型，请手动填写。');
        }

        return [
            'success' => true,
            'models' => $models,
            'manual_only' => false,
            'message' => sprintf('已加载 %d 个模型。', count($models)),
        ];
    }

    /**
     * @return array{success: bool, models: list<string>, manual_only: bool, message: string}
     */
    private function listOllama(ChatSettings $settings, ReactHttpClient $http): array
    {
        $client = $http->withBaseUri(rtrim($settings->ollamaUrl(), '/'));
        $response = $client->request(HttpRequest::get('tags'));
        if ($response->statusCode >= 400) {
            throw new \RuntimeException($this->extractErrorMessage($response->body) ?: "HTTP {$response->statusCode}");
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Ollama 模型列表响应格式无效。');
        }

        $models = [];
        foreach ($payload['models'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $models[] = $name;
            }
        }

        sort($models);

        if ($models === []) {
            return $this->manualOnly('Ollama 未返回本地模型，请确认服务已启动并已 pull 模型。');
        }

        return [
            'success' => true,
            'models' => $models,
            'manual_only' => false,
            'message' => sprintf('已加载 %d 个模型。', count($models)),
        ];
    }

    /**
     * @return array{success: bool, models: list<string>, manual_only: bool, message: string}
     */
    private function listGemini(ChatSettings $settings, ReactHttpClient $http): array
    {
        $baseUri = $settings->baseUri() !== ''
            ? rtrim($settings->baseUri(), '/')
            : 'https://generativelanguage.googleapis.com/v1beta';

        $client = $http->withHeaders([
            'Accept' => 'application/json',
            'x-goog-api-key' => $settings->apiKey(),
        ]);

        $response = $client->request(HttpRequest::get($baseUri . '/models'));
        if ($response->statusCode >= 400) {
            throw new \RuntimeException($this->extractErrorMessage($response->body) ?: "HTTP {$response->statusCode}");
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Gemini 模型列表响应格式无效。');
        }

        $models = [];
        foreach ($payload['models'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if (str_starts_with($name, 'models/')) {
                $name = substr($name, 7);
            }

            if ($name === '') {
                continue;
            }

            $methods = $item['supportedGenerationMethods'] ?? [];
            if (is_array($methods) && !in_array('generateContent', $methods, true)) {
                continue;
            }

            $models[] = $name;
        }

        sort($models);

        if ($models === []) {
            return $this->manualOnly('未获取到可用 Gemini 模型，请手动填写。');
        }

        return [
            'success' => true,
            'models' => $models,
            'manual_only' => false,
            'message' => sprintf('已加载 %d 个模型。', count($models)),
        ];
    }

    /**
     * @return array{success: bool, models: list<string>, manual_only: bool, message: string}
     */
    private function listAzure(ChatSettings $settings, ReactHttpClient $http): array
    {
        $endpoint = rtrim($settings->azureEndpoint(), '/');
        if ($endpoint === '') {
            return $this->manualOnly('请先填写 Azure Endpoint。');
        }

        $version = $settings->azureApiVersion();
        $uri = $endpoint . '/openai/models?api-version=' . rawurlencode($version);

        $client = $http->withHeaders([
            'Accept' => 'application/json',
            'api-key' => $settings->apiKey(),
        ]);

        $response = $client->request(HttpRequest::get($uri));
        if ($response->statusCode >= 400) {
            throw new \RuntimeException($this->extractErrorMessage($response->body) ?: "HTTP {$response->statusCode}");
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Azure 模型列表响应格式无效。');
        }

        $models = [];
        foreach ($payload['data'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        sort($models);

        if ($models === []) {
            return $this->manualOnly('未获取到可用 Azure 模型，请手动填写。');
        }

        return [
            'success' => true,
            'models' => $models,
            'manual_only' => false,
            'message' => sprintf('已加载 %d 个模型。', count($models)),
        ];
    }

    /**
     * @return array{success: bool, models: list<string>, manual_only: bool, message: string}
     */
    private function manualOnly(string $message): array
    {
        return [
            'success' => true,
            'models' => [],
            'manual_only' => true,
            'message' => $message,
        ];
    }

    private function isOpenAiCompatible(string $provider): bool
    {
        if (in_array($provider, self::OPENAI_COMPATIBLE, true)) {
            return true;
        }

        return !AiProviderFactory::isNativeProvider($provider);
    }

    private function resolveOpenAiBaseUri(ChatSettings $settings): string
    {
        $custom = $settings->baseUri();
        if ($custom !== '') {
            return $custom;
        }

        return match ($settings->provider()) {
            'openai', 'openai-responses', 'responses' => 'https://api.openai.com/v1',
            'deepseek' => 'https://api.deepseek.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'grok', 'xai' => 'https://api.x.ai/v1',
            'zai' => 'https://api.z.ai/api/paas/v4',
            'dashscope', 'alibaba' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'cohere' => 'https://api.cohere.ai/v1',
            default => '',
        };
    }

    /**
     * @param list<string> $models
     *
     * @return list<string>
     */
    private function filterChatModels(array $models): array
    {
        return array_values(array_filter(
            $models,
            static fn (string $id): bool => !str_contains($id, 'embedding')
                && !str_contains($id, 'tts')
                && !str_contains($id, 'whisper')
                && !str_contains($id, 'dall-e')
                && !str_contains($id, 'moderation')
                && !str_contains($id, 'transcribe'),
        ));
    }

    private function extractErrorMessage(string $body): string
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return trim($body);
        }

        foreach (['error.message', 'message'] as $path) {
            $value = $path === 'error.message'
                ? ($payload['error']['message'] ?? null)
                : ($payload['message'] ?? null);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return trim($body);
    }
}
