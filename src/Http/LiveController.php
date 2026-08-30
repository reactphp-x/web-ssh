<?php

declare(strict_types=1);

namespace App\Http;

use App\Ssh\SshLiveRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class LiveController
{
    public function __construct(private readonly SshLiveRegistry $registry)
    {
    }

    public function listSessions(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $includeFinished = ($query['include_finished'] ?? '') === '1';

        $sessions = $this->registry->listRunning();
        if ($includeFinished) {
            $sessions = array_merge($sessions, $this->registry->listFinished());
        }

        return Response::json([
            'ok' => true,
            'count' => count($sessions),
            'sessions' => $sessions,
        ]);
    }

    public function streamSession(ServerRequestInterface $request): ResponseInterface
    {
        $connId = trim((string) $request->getAttribute('id'));
        if ($connId === '') {
            return JsonResponse::error('缺少连接 ID。', 400);
        }

        $stream = $this->registry->watch($connId);
        if ($stream === null) {
            return JsonResponse::error('连接不存在或已结束。', 404);
        }

        return $stream;
    }
}
