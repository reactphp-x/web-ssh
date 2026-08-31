<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Chat\ChatSettings;
use App\Neuron\HttpClient\ReactHttpClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Alibaba\DashScopeOpenAI;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Anthropic\AnthropicVertex;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Gemini\GeminiVertex;
use NeuronAI\Providers\HuggingFace\HuggingFace;
use NeuronAI\Providers\HuggingFace\InferenceProvider;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\OpenAILikeResponses;
use NeuronAI\Providers\XAI\Grok;
use NeuronAI\Providers\ZAI\ZAI;

final class AiProviderFactory
{
    /** @var list<string> */
    private const NATIVE_PROVIDERS = [
        'openai',
        'deepseek',
        'anthropic',
        'claude',
        'gemini',
        'google',
        'ollama',
        'mistral',
        'cohere',
        'grok',
        'xai',
        'zai',
        'dashscope',
        'alibaba',
        'huggingface',
        'hf',
        'azure',
        'azure-openai',
        'openai-responses',
        'responses',
        'openailike-responses',
        'bedrock',
        'aws-bedrock',
        'aws',
        'gemini-vertex',
        'gemini_vertex',
        'anthropic-vertex',
        'anthropic_vertex',
        'openailike',
    ];

    public static function create(ChatSettings $settings, ?HttpClientInterface $http = null): AIProviderInterface
    {
        $http ??= new ReactHttpClient(timeout: $settings->httpTimeout());
        $provider = $settings->provider();
        $key = $settings->apiKey();
        $model = $settings->model();
        $baseUri = $settings->baseUri();

        if ($provider === 'openailike') {
            return self::openAiLike($baseUri, $key, $model, $http);
        }

        if ($provider === 'openailike-responses') {
            return self::openAiLikeResponses($baseUri, $key, $model, $http);
        }

        if (!self::isNativeProvider($provider)) {
            return self::openAiLike($baseUri, $key, $model, $http);
        }

        return match ($provider) {
            'deepseek' => self::instantiate(Deepseek::class, $key, $model, $http),
            'anthropic', 'claude' => new Anthropic(
                key: $key,
                model: $model,
                version: $settings->anthropicVersion(),
                max_tokens: $settings->anthropicMaxTokens(),
                httpClient: $http,
            ),
            'gemini', 'google' => self::gemini($key, $model, $baseUri, $http),
            'ollama' => new Ollama(
                url: $settings->ollamaUrl(),
                model: $model,
                httpClient: $http,
            ),
            'mistral' => self::instantiate(Mistral::class, $key, $model, $http),
            'cohere' => self::cohere($key, $model, $baseUri, $http),
            'grok', 'xai' => self::instantiate(Grok::class, $key, $model, $http),
            'zai' => self::zai($key, $model, $baseUri, $http),
            'dashscope', 'alibaba' => self::dashScope($key, $model, $baseUri, $http),
            'huggingface', 'hf' => self::huggingFace($key, $model, $settings, $http),
            'azure', 'azure-openai' => self::azure($settings, $key, $model, $http),
            'openai-responses', 'responses' => self::instantiate(OpenAIResponses::class, $key, $model, $http),
            'bedrock', 'aws-bedrock', 'aws' => self::bedrock($settings, $model),
            'gemini-vertex', 'gemini_vertex' => self::geminiVertex($settings, $model, $http),
            'anthropic-vertex', 'anthropic_vertex' => self::anthropicVertex($settings, $model, $http),
            default => self::instantiate(OpenAI::class, $key, $model, $http),
        };
    }

    public static function isNativeProvider(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), self::NATIVE_PROVIDERS, true);
    }

    /**
     * @param class-string<AIProviderInterface> $class
     */
    private static function instantiate(
        string $class,
        string $key,
        string $model,
        HttpClientInterface $http,
    ): AIProviderInterface {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('AI provider class %s is not available.', $class));
        }

        return new $class(key: $key, model: $model, httpClient: $http);
    }

    private static function openAiLike(
        string $baseUri,
        string $key,
        string $model,
        HttpClientInterface $http,
    ): OpenAILike {
        if ($baseUri === '') {
            throw new \RuntimeException(
                'Base URL is required when provider is openailike or an unknown OpenAI-compatible provider.',
            );
        }

        return new OpenAILike(
            baseUri: $baseUri,
            key: $key,
            model: $model,
            httpClient: $http,
        );
    }

    private static function openAiLikeResponses(
        string $baseUri,
        string $key,
        string $model,
        HttpClientInterface $http,
    ): OpenAILikeResponses {
        if ($baseUri === '') {
            throw new \RuntimeException(
                'Base URL is required when provider=openailike-responses.',
            );
        }

        return new OpenAILikeResponses(
            baseUri: $baseUri,
            key: $key,
            model: $model,
            httpClient: $http,
        );
    }

    private static function gemini(
        string $key,
        string $model,
        string $baseUri,
        HttpClientInterface $http,
    ): Gemini {
        if ($baseUri !== '') {
            return new Gemini(key: $key, model: $model, httpClient: $http, baseUri: $baseUri);
        }

        return new Gemini(key: $key, model: $model, httpClient: $http);
    }

    private static function cohere(
        string $key,
        string $model,
        string $baseUri,
        HttpClientInterface $http,
    ): Cohere {
        if ($baseUri !== '') {
            return new Cohere(
                key: $key,
                model: $model,
                baseUri: $baseUri,
                httpClient: $http,
            );
        }

        return new Cohere(key: $key, model: $model, httpClient: $http);
    }

    private static function zai(
        string $key,
        string $model,
        string $baseUri,
        HttpClientInterface $http,
    ): ZAI {
        if ($baseUri !== '') {
            return new ZAI(key: $key, model: $model, httpClient: $http, baseUri: $baseUri);
        }

        return new ZAI(key: $key, model: $model, httpClient: $http);
    }

    private static function dashScope(
        string $key,
        string $model,
        string $baseUri,
        HttpClientInterface $http,
    ): DashScopeOpenAI {
        if ($baseUri !== '') {
            return new DashScopeOpenAI(key: $key, model: $model, httpClient: $http, baseUri: $baseUri);
        }

        return new DashScopeOpenAI(key: $key, model: $model, httpClient: $http);
    }

    private static function huggingFace(
        string $key,
        string $model,
        ChatSettings $settings,
        HttpClientInterface $http,
    ): HuggingFace {
        $inferenceProvider = InferenceProvider::tryFrom($settings->huggingFaceInferenceProvider())
            ?? InferenceProvider::HF_INFERENCE;

        return new HuggingFace(
            key: $key,
            model: $model,
            inferenceProvider: $inferenceProvider,
            httpClient: $http,
        );
    }

    private static function azure(
        ChatSettings $settings,
        string $key,
        string $model,
        HttpClientInterface $http,
    ): AzureOpenAI {
        $endpoint = $settings->azureEndpoint();
        if ($endpoint === '') {
            throw new \RuntimeException(
                'NEURON_AI_AZURE_ENDPOINT is required when NEURON_AI_PROVIDER=azure.',
            );
        }

        return new AzureOpenAI(
            key: $key,
            endpoint: $endpoint,
            model: $model,
            version: $settings->azureApiVersion(),
            httpClient: $http,
        );
    }

    private static function bedrock(ChatSettings $settings, string $model): BedrockRuntime
    {
        if (!class_exists(BedrockRuntimeClient::class)) {
            throw new \RuntimeException(
                'Install aws/aws-sdk-php to use NEURON_AI_PROVIDER=bedrock.',
            );
        }

        $config = [
            'version' => 'latest',
            'region' => $settings->awsRegion(),
        ];

        $awsKey = $settings->awsAccessKey();
        $awsSecret = $settings->awsSecretKey();
        if ($awsKey !== '' && $awsSecret !== '') {
            $config['credentials'] = [
                'key' => $awsKey,
                'secret' => $awsSecret,
            ];
        }

        return new BedrockRuntime(
            bedrockRuntimeClient: new BedrockRuntimeClient($config),
            model: $model,
        );
    }

    private static function geminiVertex(
        ChatSettings $settings,
        string $model,
        HttpClientInterface $http,
    ): GeminiVertex {
        if (!class_exists(ServiceAccountCredentials::class)) {
            throw new \RuntimeException(
                'Install google/auth to use NEURON_AI_PROVIDER=gemini-vertex.',
            );
        }

        $credentials = $settings->vertexCredentialsPath();
        $projectId = $settings->vertexProjectId();
        if ($credentials === '' || $projectId === '') {
            throw new \RuntimeException(
                'NEURON_AI_VERTEX_CREDENTIALS and NEURON_AI_VERTEX_PROJECT are required for gemini-vertex.',
            );
        }

        return new GeminiVertex(
            pathJsonCredentials: $credentials,
            location: $settings->vertexLocation(),
            projectId: $projectId,
            model: $model,
            httpClient: $http,
        );
    }

    private static function anthropicVertex(
        ChatSettings $settings,
        string $model,
        HttpClientInterface $http,
    ): AnthropicVertex {
        if (!class_exists(ServiceAccountCredentials::class)) {
            throw new \RuntimeException(
                'Install google/auth to use NEURON_AI_PROVIDER=anthropic-vertex.',
            );
        }

        $credentials = $settings->vertexCredentialsPath();
        $projectId = $settings->vertexProjectId();
        if ($credentials === '' || $projectId === '') {
            throw new \RuntimeException(
                'NEURON_AI_VERTEX_CREDENTIALS and NEURON_AI_VERTEX_PROJECT are required for anthropic-vertex.',
            );
        }

        return new AnthropicVertex(
            pathJsonCredentials: $credentials,
            location: $settings->vertexLocation(),
            projectId: $projectId,
            model: $model,
            httpClient: $http,
        );
    }
}
