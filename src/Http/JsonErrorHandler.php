<?php

declare(strict_types=1);

namespace App\Http;

use FrameworkX\ErrorHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use ReactphpX\Framework\Environment;
use RuntimeException;

final class JsonErrorHandler extends ErrorHandler
{
    public function __construct(
        private readonly Environment $environment,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return ResponseInterface|PromiseInterface<ResponseInterface>|\Generator
     */
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            return $this->exceptionResponse($request, $exception);
        }

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        if ($response instanceof PromiseInterface) {
            return $response->then(
                function ($response) use ($request) {
                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    return $this->serverError($request, 'Expected request handler to return a valid response.');
                },
                fn (\Throwable $exception) => $this->exceptionResponse($request, $exception),
            );
        }

        if ($response instanceof \Generator) {
            return $this->coroutine($request, $response);
        }

        return $this->serverError($request, 'Expected request handler to return a valid response.');
    }

    private function coroutine(ServerRequestInterface $request, \Generator $generator): \Generator
    {
        do {
            try {
                if (!$generator->valid()) {
                    $response = $generator->getReturn();
                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    return $this->serverError($request, 'Expected request handler to return a valid response.');
                }
            } catch (\Throwable $exception) {
                return $this->exceptionResponse($request, $exception);
            }

            $promise = $generator->current();
            if (!$promise instanceof PromiseInterface) {
                return $this->serverError($request, 'Expected request handler to yield a promise.');
            }

            try {
                $next = yield $promise;
            } catch (\Throwable $exception) {
                try {
                    $generator->throw($exception);
                    continue;
                } catch (\Throwable $exception) {
                    return $this->exceptionResponse($request, $exception);
                }
            }

            try {
                $generator->send($next);
            } catch (\Throwable $exception) {
                return $this->exceptionResponse($request, $exception);
            }
        } while (true);
    }

    private function exceptionResponse(ServerRequestInterface $request, \Throwable $exception): ResponseInterface
    {
        $this->logger->error($exception->getMessage(), [
            'exception' => [
                'class' => $exception::class,
                'at' => $exception->getFile() . ':' . $exception->getLine(),
            ],
            'request' => [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ],
        ]);

        if ($exception instanceof RuntimeException) {
            return JsonResponse::error(
                $this->isDebug() ? $exception->getMessage() : '服务器内部错误，请稍后重试。',
                500,
                $this->debugPayload($exception),
            );
        }

        return $this->serverError(
            $request,
            $this->isDebug() ? $exception->getMessage() : '服务器内部错误，请稍后重试。',
            $exception,
        );
    }

    private function serverError(
        ServerRequestInterface $request,
        string $message,
        ?\Throwable $exception = null,
    ): ResponseInterface {
        if ($exception !== null) {
            $this->logger->error($message, [
                'exception' => [
                    'class' => $exception::class,
                    'at' => $exception->getFile() . ':' . $exception->getLine(),
                ],
                'request' => [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                ],
            ]);
        }

        return JsonResponse::error($message, 500, $exception !== null ? $this->debugPayload($exception) : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function debugPayload(\Throwable $exception): array
    {
        if (!$this->isDebug()) {
            return [];
        }

        return [
            'debug' => [
                'class' => $exception::class,
                'at' => $exception->getFile() . ':' . $exception->getLine(),
            ],
        ];
    }

    private function isDebug(): bool
    {
        return $this->environment->string('APP_ENV', 'production') === 'development';
    }
}
