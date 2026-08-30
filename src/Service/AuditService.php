<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\RequestAuth;
use App\Repository\AuditLogRepository;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class AuditService
{
    public function __construct(private readonly AuditLogRepository $logs)
    {
    }

    public function log(
        ServerRequestInterface $request,
        string $action,
        string $resource,
        ?int $resourceId = null,
        ?string $detail = null,
    ): PromiseInterface {
        return $this->logs->write(
            RequestAuth::username($request),
            $action,
            $resource,
            $resourceId,
            $detail,
            RequestAuth::clientIp($request),
        );
    }

    public function logAs(
        string $username,
        string $ip,
        string $action,
        string $resource,
        ?int $resourceId = null,
        ?string $detail = null,
    ): PromiseInterface {
        return $this->logs->write($username, $action, $resource, $resourceId, $detail, $ip);
    }
}
