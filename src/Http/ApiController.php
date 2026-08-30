<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\AuditLogRepository;
use App\Repository\HostGroupRepository;
use App\Repository\HostRepository;
use App\Repository\SessionRepository;
use App\Repository\TwoFactorRepository;
use App\Service\AuditService;
use App\Service\HostService;
use App\Service\SessionService;
use App\Service\SshProbeService;
use App\Ssh\SshTarget;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class ApiController
{
    public function __construct(
        private readonly HostService $hostService,
        private readonly HostRepository $hosts,
        private readonly HostGroupRepository $groups,
        private readonly SessionRepository $sessions,
        private readonly AuditLogRepository $auditLogs,
        private readonly AuditService $audit,
        private readonly SessionService $sessionService,
        private readonly SshProbeService $probe,
        private readonly ?TwoFactorRepository $twoFactor = null,
    ) {
    }

    public function me(ServerRequestInterface $request): ResponseInterface|PromiseInterface
    {
        $username = RequestAuth::username($request);
        $payload = [
            'username' => $username,
            'auth' => 'basic',
            'two_factor' => [
                'enabled' => $username !== 'anonymous',
                'configured' => false,
                'verified' => (bool) $request->getAttribute('2fa_verified'),
            ],
        ];

        if ($username === 'anonymous') {
            return JsonResponse::json($payload);
        }

        if ($this->twoFactor === null) {
            return JsonResponse::json($payload);
        }

        return $this->twoFactor
            ->findByUsername($username)
            ->then(function (?array $record) use ($payload, $request): ResponseInterface {
                $payload['two_factor']['configured'] = $record !== null;
                $payload['two_factor']['verified'] = (bool) $request->getAttribute('2fa_verified');
                if ($record !== null) {
                    $payload['two_factor']['label'] = $record['label'];
                }

                return JsonResponse::json($payload);
            });
    }

    public function listGroups(): PromiseInterface
    {
        return $this->groups->listAll()->then(static fn (array $items) => JsonResponse::json(['items' => $items]));
    }

    public function listKeyPaths(): ResponseInterface
    {
        $paths = $this->hostService->suggestedKeyPaths();
        $home = getenv('HOME') ?: '';
        $displayPaths = array_map(
            static function (string $path) use ($home): string {
                if ($home !== '' && str_starts_with($path, $home)) {
                    return '~' . substr($path, strlen($home));
                }

                return $path;
            },
            $paths,
        );
        $defaultPath = $displayPaths[0] ?? '~/.ssh/id_rsa';

        return JsonResponse::json([
            'default' => $defaultPath,
            'items' => array_map(
                static function (string $path) use ($home): array {
                    $display = $home !== '' && str_starts_with($path, $home)
                        ? '~' . substr($path, strlen($home))
                        : $path;

                    return [
                        'path' => $display,
                        'readable' => is_readable($path),
                    ];
                },
                $paths,
            ),
        ]);
    }

    public function listHosts(ServerRequestInterface $request): PromiseInterface
    {
        $query = $request->getQueryParams();

        return $this->hosts
            ->paginate(
                (int) ($query['page'] ?? 1),
                (int) ($query['per_page'] ?? 10),
                isset($query['q']) ? (string) $query['q'] : null,
                isset($query['group_id']) ? (int) $query['group_id'] : null,
                isset($query['tag']) ? (string) $query['tag'] : null,
            )
            ->then(static fn (array $result) => JsonResponse::json($result));
    }

    public function listHostOptions(): PromiseInterface
    {
        return $this->hosts->listOptions()->then(static fn (array $items) => JsonResponse::json(['items' => $items]));
    }

    public function getHost(int $id): PromiseInterface
    {
        return $this->hosts->findPublicById($id)->then(static function (?array $host) {
            if ($host === null) {
                return JsonResponse::error('主机不存在。', 404);
            }

            return JsonResponse::json($host);
        });
    }

    public function createHost(ServerRequestInterface $request): PromiseInterface
    {
        $input = HttpJson::body($request);
        $username = RequestAuth::username($request);

        return $this->hostService
            ->validateInput($input)
            ->then(function (array $validation) use ($input, $request, $username): PromiseInterface {
                if (!$validation['valid']) {
                    return resolve(JsonResponse::error((string) $validation['message']));
                }

                return $this->hosts
                    ->nameExists((string) $input['name'])
                    ->then(function (bool $exists) use ($input, $request, $username): PromiseInterface {
                        if ($exists) {
                            return resolve(JsonResponse::error('主机名称已存在，请更换。'));
                        }

                        $payload = $this->hostService->buildPersistPayload($input, $username);

                        return $this->hosts
                            ->create($payload)
                            ->then(function (int $id) use ($request, $payload): PromiseInterface {
                                return $this->audit
                                    ->log($request, 'create', 'host', $id, $payload['name'])
                                    ->then(static fn () => JsonResponse::json(['id' => $id, 'message' => '创建成功'], 201));
                            });
                    });
            });
    }

    public function updateHost(ServerRequestInterface $request, int $id): PromiseInterface
    {
        $input = HttpJson::body($request);

        return $this->hosts
            ->findById($id)
            ->then(function (?array $existing) use ($input, $request, $id): PromiseInterface {
                if ($existing === null) {
                    return resolve(JsonResponse::error('主机不存在。', 404));
                }

                $authTypeChanged = (string) ($input['auth_type'] ?? $existing['auth_type']) !== (string) $existing['auth_type'];
                $keySource = (string) ($input['private_key_source'] ?? $existing['private_key_source'] ?? 'path');
                $secretEmpty = ($input['password'] ?? '') === ''
                    && ($keySource === 'path'
                        ? ($input['private_key_path'] ?? '') === ''
                        : ($input['private_key'] ?? '') === '');
                $input['keep_secret'] = !$authTypeChanged && $secretEmpty;

                return $this->hostService
                    ->validateInput($input, true, $id)
                    ->then(function (array $validation) use ($input, $request, $id, $existing): PromiseInterface {
                        if (!$validation['valid']) {
                            return resolve(JsonResponse::error((string) $validation['message']));
                        }

                        return $this->hosts
                            ->nameExists((string) $input['name'], $id)
                            ->then(function (bool $exists) use ($input, $request, $id, $existing): PromiseInterface {
                                if ($exists) {
                                    return resolve(JsonResponse::error('主机名称已存在，请更换。'));
                                }

                                $payload = $this->hostService->buildPersistPayload($input, $existing['created_by'], $existing);

                                return $this->hosts
                                    ->update($id, $payload)
                                    ->then(function () use ($request, $id, $payload): PromiseInterface {
                                        return $this->audit
                                            ->log($request, 'update', 'host', $id, $payload['name'])
                                            ->then(static fn () => JsonResponse::json(['message' => '更新成功']));
                                    });
                            });
                    });
            });
    }

    public function deleteHost(ServerRequestInterface $request, int $id): PromiseInterface
    {
        return $this->hosts
            ->findPublicById($id)
            ->then(function (?array $host) use ($request, $id): PromiseInterface {
                if ($host === null) {
                    return resolve(JsonResponse::error('主机不存在。', 404));
                }

                return $this->hosts
                    ->delete($id)
                    ->then(function (bool $deleted) use ($request, $id, $host): PromiseInterface {
                        if (!$deleted) {
                            return resolve(JsonResponse::error('删除失败。', 500));
                        }

                        return $this->audit
                            ->log($request, 'delete', 'host', $id, (string) $host['name'])
                            ->then(static fn () => JsonResponse::json(['message' => '删除成功']));
                    });
            });
    }

    public function testHostInput(ServerRequestInterface $request): PromiseInterface
    {
        $input = HttpJson::body($request);
        $hostId = (int) ($input['id'] ?? 0);

        if ($hostId <= 0) {
            return $this->hostService
                ->validateInput($input)
                ->then(function (array $validation) use ($input): PromiseInterface {
                    if (!$validation['valid']) {
                        return resolve(JsonResponse::json(['success' => false, 'message' => $validation['message']]));
                    }

                    return $this->hostService
                        ->resolveTargetFromInput($input)
                        ->then(function (SshTarget $target): PromiseInterface {
                            return $this->probe
                                ->test($target)
                                ->then(static fn (array $result) => JsonResponse::json($result));
                        });
                });
        }

        return $this->hosts
            ->findById($hostId)
            ->then(function (?array $existing) use ($input, $hostId, $request): PromiseInterface {
                if ($existing === null) {
                    return resolve(JsonResponse::error('主机不存在。', 404));
                }

                $authTypeChanged = (string) ($input['auth_type'] ?? $existing['auth_type']) !== (string) $existing['auth_type'];
                $keySource = (string) ($input['private_key_source'] ?? $existing['private_key_source'] ?? 'path');
                $secretEmpty = ($input['password'] ?? '') === ''
                    && ($keySource === 'path'
                        ? ($input['private_key_path'] ?? '') === ''
                        : ($input['private_key'] ?? '') === '');
                $input['keep_secret'] = !$authTypeChanged && $secretEmpty;

                return $this->hostService
                    ->validateInput($input, true, $hostId)
                    ->then(function (array $validation) use ($input, $existing, $hostId, $request): PromiseInterface {
                        if (!$validation['valid']) {
                            return resolve(JsonResponse::json(['success' => false, 'message' => $validation['message']]));
                        }

                        return $this->hostService
                            ->resolveTargetFromInput($input, $existing)
                            ->then(function (SshTarget $target) use ($request, $hostId): PromiseInterface {
                                return $this->probe
                                    ->test($target)
                                    ->then(function (array $result) use ($request, $hostId): PromiseInterface {
                                        return $this->audit
                                            ->log($request, 'test', 'host', $hostId, $result['message'])
                                            ->then(static fn () => JsonResponse::json($result));
                                    });
                            });
                    });
            });
    }

    public function testHostById(ServerRequestInterface $request, int $id): PromiseInterface
    {
        return $this->hosts
            ->findById($id)
            ->then(function (?array $host) use ($request, $id): PromiseInterface {
                if ($host === null) {
                    return resolve(JsonResponse::error('主机不存在。', 404));
                }

                return $this->hostService
                    ->resolveSshTarget($host)
                    ->then(function (SshTarget $target) use ($request, $id): PromiseInterface {
                        return $this->probe
                            ->test($target)
                            ->then(function (array $result) use ($request, $id): PromiseInterface {
                                return $this->audit
                                    ->log($request, 'test', 'host', $id, $result['message'])
                                    ->then(static fn () => JsonResponse::json($result));
                            });
                    });
            });
    }

    public function listSessions(ServerRequestInterface $request): PromiseInterface
    {
        $query = $request->getQueryParams();

        return $this->sessions
            ->paginate(
                (int) ($query['page'] ?? 1),
                (int) ($query['per_page'] ?? 10),
                isset($query['username']) ? (string) $query['username'] : null,
                isset($query['host_id']) ? (int) $query['host_id'] : null,
                isset($query['from']) ? (string) $query['from'] : null,
                isset($query['to']) ? (string) $query['to'] : null,
            )
            ->then(static fn (array $result) => JsonResponse::json($result));
    }

    public function getSession(int $id): PromiseInterface
    {
        return $this->sessions->findById($id)->then(static function (?array $session) {
            if ($session === null) {
                return JsonResponse::error('会话不存在。', 404);
            }

            return JsonResponse::json($session);
        });
    }

    public function listAuditLogs(ServerRequestInterface $request): PromiseInterface
    {
        $query = $request->getQueryParams();

        return $this->auditLogs
            ->paginate(
                (int) ($query['page'] ?? 1),
                (int) ($query['per_page'] ?? 20),
                isset($query['username']) ? (string) $query['username'] : null,
                isset($query['action']) ? (string) $query['action'] : null,
                isset($query['resource']) ? (string) $query['resource'] : null,
            )
            ->then(static fn (array $result) => JsonResponse::json($result));
    }
}
