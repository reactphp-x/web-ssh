<?php

declare(strict_types=1);

namespace App\Http;

use App\Recording\SessionRecorder;
use App\Repository\SessionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

final class RecordingController
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly SessionRecorder $recorder,
    ) {
    }

    public function manifest(ServerRequestInterface $request): PromiseInterface|ResponseInterface
    {
        $sessionId = (int) $request->getAttribute('id');

        return $this->sessions
            ->findById($sessionId)
            ->then(function (?array $session) use ($sessionId): ResponseInterface {
                if ($session === null) {
                    return JsonResponse::error('该会话没有可用的回放录像。', 404);
                }

                if (!$this->recorder->ensureRecordingAvailable(
                    $sessionId,
                    $session['recording_url'] ?? null,
                    $session['start_time'] ?? null,
                ) && empty($session['recording_url'])) {
                    return JsonResponse::error('该会话没有可用的回放录像。', 404);
                }

                $manifest = $this->recorder->readManifest(
                    $sessionId,
                    $session['recording_url'] ?? null,
                    $session['start_time'] ?? null,
                );
                if ($manifest === null) {
                    return JsonResponse::error('回放清单不存在或已损坏。', 404);
                }

                return JsonResponse::json([
                    'ok' => true,
                    'session_id' => $sessionId,
                    'manifest' => $manifest,
                    'parts' => array_map(
                        static fn (array $part): array => [
                            'name' => $part['name'],
                            'url' => '/api/sessions/' . $sessionId . '/recording/' . $part['name'],
                        ],
                        $manifest['parts'] ?? [],
                    ),
                ]);
            });
    }

    public function part(ServerRequestInterface $request): PromiseInterface|ResponseInterface
    {
        $sessionId = (int) $request->getAttribute('id');
        $partName = (string) $request->getAttribute('part');

        return $this->sessions
            ->findById($sessionId)
            ->then(function (?array $session) use ($sessionId, $partName): ResponseInterface {
                if ($session === null) {
                    return JsonResponse::error('该会话没有可用的回放录像。', 404);
                }

                if (!$this->recorder->ensureRecordingAvailable(
                    $sessionId,
                    $session['recording_url'] ?? null,
                    $session['start_time'] ?? null,
                ) && empty($session['recording_url'])) {
                    return JsonResponse::error('该会话没有可用的回放录像。', 404);
                }

                $path = $this->recorder->resolvePartPath(
                    $sessionId,
                    $partName,
                    $session['recording_url'] ?? null,
                    $session['start_time'] ?? null,
                );
                if ($path === null) {
                    return JsonResponse::error('回放分片不存在。', 404);
                }

                $body = file_get_contents($path);
                if ($body === false) {
                    return JsonResponse::error('读取回放文件失败。', 500);
                }

                return new Response(
                    200,
                    [
                        'Content-Type' => 'application/x-asciicast',
                        'Cache-Control' => 'private, max-age=3600',
                    ],
                    $body,
                );
            });
    }
}
