<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuthRateLimitRepository;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class AuthRateLimiter
{
    public function __construct(
        private readonly AuthRateLimitRepository $repository,
        private readonly int $maxFailures = 5,
        private readonly int $lockSeconds = 900,
    ) {
    }

    public function ensureAllowed(string $bucket): PromiseInterface
    {
        return $this->repository
            ->isLocked($bucket)
            ->then(function (bool $locked): PromiseInterface {
                if ($locked) {
                    $minutes = max(1, (int) ceil($this->lockSeconds / 60));

                    return resolve([
                        'allowed' => false,
                        'message' => sprintf('尝试次数过多，请 %d 分钟后再试。', $minutes),
                    ]);
                }

                return resolve(['allowed' => true]);
            });
    }

    public function recordFailure(string $bucket): PromiseInterface
    {
        return $this->repository->recordFailure($bucket, $this->maxFailures, $this->lockSeconds);
    }

    public function clear(string $bucket): PromiseInterface
    {
        return $this->repository->clear($bucket);
    }
}
