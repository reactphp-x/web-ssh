<?php

declare(strict_types=1);

namespace App\Neuron\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use React\Http\Browser;
use React\Promise\Timer\TimeoutException as ReactPromiseTimeoutException;
use React\Stream\ReadableStreamInterface;
use Throwable;

use function React\Async\await;

/**
 * Neuron HttpClientInterface backed by ReactPHP Browser. await() yields to the event loop.
 */
final class ReactHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    /**
     * @param array<string, string> $customHeaders
     */
    public function __construct(
        protected readonly array $customHeaders = [],
        protected readonly float $timeout = 30.0,
        protected readonly ?Browser $browser = null,
        protected readonly int $timeoutRetries = 1,
        protected readonly ?HttpStreamScope $httpStreamScope = null,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $maxRetry = max(0, $this->timeoutRetries);

        for ($attempt = 0; $attempt <= $maxRetry; $attempt++) {
            try {
                $uri = $this->buildUri($request);
                [$headers, $body] = $this->prepareRequestPayload($request);
                $response = await($this->resolveBrowser()->request($request->method->value, $uri, $headers, $body));

                return new HttpResponse(
                    statusCode: $response->getStatusCode(),
                    body: (string) $response->getBody(),
                    headers: $response->getHeaders(),
                );
            } catch (Throwable $e) {
                if ($this->isTimeoutError($e) && $attempt < $maxRetry) {
                    continue;
                }

                throw $this->wrap($request, $e);
            }
        }

        throw new \LogicException('HTTP request retry loop exited without result');
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $maxRetry = max(0, $this->timeoutRetries);

        for ($attempt = 0; $attempt <= $maxRetry; $attempt++) {
            try {
                $uri = $this->buildUri($request);
                [$headers, $body] = $this->prepareRequestPayload($request);
                $response = await($this->resolveBrowser()->requestStreaming($request->method->value, $uri, $headers, $body));
                $stream = $response->getBody();
                if (!$stream instanceof ReadableStreamInterface) {
                    throw new \RuntimeException('Streaming response body must implement ReadableStreamInterface');
                }

                $reactStream = new ReactStream($stream);
                $this->httpStreamScope?->attach($reactStream);

                return $reactStream;
            } catch (Throwable $e) {
                if ($this->isTimeoutError($e) && $attempt < $maxRetry) {
                    continue;
                }

                throw $this->wrap($request, $e);
            }
        }

        throw new \LogicException('HTTP stream retry loop exited without result');
    }

    public function withBaseUri(string $baseUri): self
    {
        $new = new self($this->customHeaders, $this->timeout, $this->browser, $this->timeoutRetries, $this->httpStreamScope);
        $new->baseUri = $baseUri;

        return $new;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $new = new self([...$this->customHeaders, ...$headers], $this->timeout, $this->browser, $this->timeoutRetries, $this->httpStreamScope);
        $new->baseUri = $this->baseUri;

        return $new;
    }

    public function withTimeout(float $timeout): self
    {
        $new = new self($this->customHeaders, $timeout, $this->browser, $this->timeoutRetries, $this->httpStreamScope);
        $new->baseUri = $this->baseUri;

        return $new;
    }

    public function withHttpStreamScope(?HttpStreamScope $httpStreamScope): self
    {
        $new = new self($this->customHeaders, $this->timeout, $this->browser, $this->timeoutRetries, $httpStreamScope);
        $new->baseUri = $this->baseUri;

        return $new;
    }

    private function wrap(HttpRequest $request, Throwable $e): HttpException
    {
        if ($e instanceof HttpException) {
            return $e;
        }

        return new HttpException(
            "Network error during {$request->method->value} {$request->uri}: {$e->getMessage()}",
            $request,
            null,
            $e,
        );
    }

    private function isTimeoutError(Throwable $e): bool
    {
        if ($e instanceof ReactPromiseTimeoutException) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'etimedout');
    }

    private function resolveBrowser(): Browser
    {
        $browser = $this->browser ?? new Browser();

        return $browser->withTimeout($this->timeout);
    }

    private function buildUri(HttpRequest $request): string
    {
        return $this->baseUri !== ''
            ? trim($this->baseUri, '/') . ($request->uri !== '' ? '/' . trim($request->uri, '/') : '')
            : $request->uri;
    }

    /**
     * @return array{0: array<string, string|string[]>, 1: string}
     */
    private function prepareRequestPayload(HttpRequest $request): array
    {
        $headers = [...$this->customHeaders, ...$request->headers];
        $body = '';

        if ($request->body === null) {
            return [$headers, $body];
        }

        if (is_array($request->body)) {
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
            $body = json_encode($request->body) ?: '';
        } else {
            $body = $request->body;
        }

        return [$headers, $body];
    }
}
