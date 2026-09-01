<?php

declare(strict_types=1);

namespace App\Chat;

use ReactphpX\Redis\Pool as RedisPool;
use Throwable;

use function React\Async\await;

/**
 * Per-thread "auto-approve run_ssh_command for this session" flag.
 * Redis first, in-process fallback if Redis is unavailable.
 */
final class CommandApprovalTrust
{
    private const TTL = 86400;

    /** @var array<string, true> */
    private static array $memory = [];

    public function __construct(
        private readonly ?RedisPool $redis = null,
    ) {
    }

    public function enable(string $threadKey): void
    {
        if ($threadKey === '') {
            return;
        }

        self::$memory[$threadKey] = true;
        $this->tryRedis(function () use ($threadKey) {
            return await($this->redis->set($this->key($threadKey), '1', 'EX', self::TTL));
        });
    }

    public function disable(string $threadKey): void
    {
        if ($threadKey === '') {
            return;
        }

        unset(self::$memory[$threadKey]);
        $this->tryRedis(function () use ($threadKey) {
            return await($this->redis->del($this->key($threadKey)));
        });
    }

    public function isEnabled(string $threadKey): bool
    {
        if ($threadKey === '') {
            return false;
        }

        if (isset(self::$memory[$threadKey])) {
            return true;
        }

        if ($this->tryRedis(function () use ($threadKey) {
            return await($this->redis->get($this->key($threadKey)));
        }, $value)) {
            return $value === '1' || $value === 1 || $value === true;
        }

        return false;
    }

    public function resetMemory(): void
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
        return 'webssh:cmd_auto_approve:' . sha1($threadKey);
    }
}
