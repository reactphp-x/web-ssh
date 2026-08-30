<?php

declare(strict_types=1);

namespace App\Chat;

use ReactphpX\Redis\Pool as RedisPool;
use Throwable;

use function React\Async\await;

/**
 * Per-thread busy lock. Redis first, in-process fallback if Redis is down.
 */
final class ThreadLock
{
    /** @var array<string, array{at: int}> */
    private static array $memory = [];

    public function __construct(
        private readonly ?RedisPool $redis = null,
        private readonly int $ttl = 180,
    ) {
    }

    public function acquire(string $threadKey): bool
    {
        if ($this->tryRedis(function () use ($threadKey) {
            return await($this->redis->set($this->key($threadKey), (string) time(), 'EX', $this->ttl, 'NX'));
        }, $ok)) {
            return $ok === 'OK' || $ok === true;
        }

        $existing = self::$memory[$threadKey] ?? null;
        if ($existing !== null && (time() - $existing['at']) < $this->ttl) {
            return false;
        }
        self::$memory[$threadKey] = ['at' => time()];

        return true;
    }

    public function heartbeat(string $threadKey): void
    {
        if ($this->tryRedis(function () use ($threadKey) {
            return await($this->redis->set($this->key($threadKey), (string) time(), 'EX', $this->ttl));
        })) {
            return;
        }

        self::$memory[$threadKey] = ['at' => time()];
    }

    public function release(string $threadKey): void
    {
        $this->tryRedis(function () use ($threadKey) {
            return await($this->redis->del($this->key($threadKey)));
        });
        unset(self::$memory[$threadKey]);
    }

    public function reset(): void
    {
        self::$memory = [];
    }

    /**
     * @param callable(): mixed $call
     */
    private function tryRedis(callable $call, mixed &$result = null): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            $result = $call();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function key(string $threadKey): string
    {
        return 'neuron-chat:busy:' . sha1($threadKey);
    }
}
