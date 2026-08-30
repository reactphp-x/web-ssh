<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SessionRepository;
use React\Promise\PromiseInterface;

final class SessionService
{
    public function __construct(private readonly SessionRepository $sessions)
    {
    }

    public function start(string $username, int $hostId): PromiseInterface
    {
        return $this->sessions->create($username, $hostId, 'pending');
    }

    public function markSuccess(int $sessionId): PromiseInterface
    {
        return $this->sessions->finish($sessionId, 'success');
    }

    public function markConnected(int $sessionId): PromiseInterface
    {
        return $this->sessions->markConnected($sessionId);
    }

    public function markFailed(int $sessionId, string $message): PromiseInterface
    {
        return $this->sessions->finish($sessionId, 'failed', $message);
    }

    public function markClosed(int $sessionId): PromiseInterface
    {
        return $this->sessions->finish($sessionId, 'success');
    }
}
