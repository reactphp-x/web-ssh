<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\AuditService;
use App\Service\CommandPolicyService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use Throwable;

final class CommandPolicyController
{
    public function __construct(
        private readonly CommandPolicyService $policies,
        private readonly AuditService $audit,
    ) {
    }

    public function show(): PromiseInterface
    {
        return $this->policies
            ->show()
            ->then(static fn (array $payload): ResponseInterface => JsonResponse::json($payload));
    }

    public function save(ServerRequestInterface $request): PromiseInterface
    {
        $input = HttpJson::body($request);

        return $this->policies
            ->save($input)
            ->then(function (array $payload) use ($request): ResponseInterface {
                $this->audit->log($request, 'update', 'command_policy', $payload['id'] ?? null, '更新命令策略');

                return JsonResponse::json($payload);
            })
            ->otherwise(fn (Throwable $e): ResponseInterface => $this->mapError($e));
    }

    public function delete(ServerRequestInterface $request): PromiseInterface
    {
        $id = (int) $request->getAttribute('id');

        return $this->policies
            ->delete($id)
            ->then(function () use ($request, $id): ResponseInterface {
                $this->audit->log($request, 'delete', 'command_policy', $id, '删除命令策略');

                return JsonResponse::json(['ok' => true]);
            });
    }

    private function mapError(Throwable $e): ResponseInterface
    {
        if ($e instanceof InvalidArgumentException) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        return JsonResponse::error($e->getMessage(), 500);
    }
}
