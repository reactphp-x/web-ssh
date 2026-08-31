<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\AiSettingsService;
use App\Service\AuditService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use Throwable;

final class AiSettingsController
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly AuditService $audit,
    ) {
    }

    public function show(): PromiseInterface
    {
        return $this->settings
            ->get()
            ->then(static fn (array $payload): ResponseInterface => JsonResponse::json($payload));
    }

    public function showProfile(ServerRequestInterface $request): PromiseInterface
    {
        $id = (int) $request->getAttribute('id');

        return $this->settings
            ->getProfile($id)
            ->then(static fn (array $payload): ResponseInterface => JsonResponse::json($payload))
            ->otherwise(function (Throwable $e): ResponseInterface {
                if ($e instanceof InvalidArgumentException) {
                    return JsonResponse::error($e->getMessage(), 404);
                }

                return JsonResponse::error($e->getMessage(), 500);
            });
    }

    public function create(ServerRequestInterface $request): PromiseInterface
    {
        $input = HttpJson::body($request);

        return $this->settings
            ->create($input, RequestAuth::username($request))
            ->then(function (array $payload) use ($request): ResponseInterface {
                $this->audit->log($request, 'create', 'ai_profile', null, '新建 AI 配置');

                return JsonResponse::json($payload);
            })
            ->otherwise(fn (Throwable $e): ResponseInterface => $this->mapError($e));
    }

    public function update(ServerRequestInterface $request): PromiseInterface
    {
        $id = (int) $request->getAttribute('id');
        $input = HttpJson::body($request);

        return $this->settings
            ->update($id, $input, RequestAuth::username($request))
            ->then(function (array $payload) use ($request, $id): ResponseInterface {
                $this->audit->log($request, 'update', 'ai_profile', $id, '更新 AI 配置');

                return JsonResponse::json($payload);
            })
            ->otherwise(fn (Throwable $e): ResponseInterface => $this->mapError($e));
    }

    public function delete(ServerRequestInterface $request): PromiseInterface
    {
        $id = (int) $request->getAttribute('id');

        return $this->settings
            ->delete($id, RequestAuth::username($request))
            ->then(function (array $payload) use ($request, $id): ResponseInterface {
                $this->audit->log($request, 'delete', 'ai_profile', $id, '删除 AI 配置');

                return JsonResponse::json($payload);
            })
            ->otherwise(fn (Throwable $e): ResponseInterface => $this->mapError($e));
    }

    public function select(ServerRequestInterface $request): PromiseInterface
    {
        $id = (int) $request->getAttribute('id');

        return $this->settings
            ->select($id, RequestAuth::username($request))
            ->then(function (array $payload) use ($request, $id): ResponseInterface {
                $this->audit->log($request, 'select', 'ai_profile', $id, '切换 AI 配置');

                return JsonResponse::json($payload);
            })
            ->otherwise(fn (Throwable $e): ResponseInterface => $this->mapError($e));
    }

    public function test(ServerRequestInterface $request): PromiseInterface
    {
        $input = HttpJson::body($request);

        return $this->settings
            ->test($input)
            ->then(static fn (array $result): ResponseInterface => JsonResponse::json($result));
    }

    public function models(ServerRequestInterface $request): PromiseInterface
    {
        $input = HttpJson::body($request);

        return $this->settings
            ->listModels($input)
            ->then(static fn (array $result): ResponseInterface => JsonResponse::json($result));
    }

    private function mapError(Throwable $e): ResponseInterface
    {
        if ($e instanceof InvalidArgumentException) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        return JsonResponse::error($e->getMessage(), 500);
    }
}
