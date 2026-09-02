<?php

declare(strict_types=1);

namespace App\Chat;

use ReactphpX\Redis\Pool as RedisPool;
use Throwable;

use function React\Async\await;

/**
 * Per-thread command approval mode for AI run_ssh_command.
 */
final class CommandApprovalTrust
{
    private const TTL = 86400;

    /** @var array<string, string> */
    private static array $memory = [];

    public function __construct(
        private readonly ?RedisPool $redis = null,
    ) {
    }

    public function setMode(string $threadKey, CommandApprovalMode $mode): void
    {
        if ($threadKey === '') {
            return;
        }

        if ($mode === CommandApprovalMode::AlwaysApprove) {
            $this->disable($threadKey);

            return;
        }

        self::$memory[$threadKey] = $mode->value;
        $this->tryRedis(function () use ($threadKey, $mode) {
            return await($this->redis->set($this->key($threadKey), $mode->value, 'EX', self::TTL));
        });
    }

    public function getMode(string $threadKey): CommandApprovalMode
    {
        if ($threadKey === '') {
            return CommandApprovalMode::AlwaysApprove;
        }

        if (isset(self::$memory[$threadKey])) {
            return CommandApprovalMode::fromMixed(self::$memory[$threadKey]);
        }

        if ($this->tryRedis(function () use ($threadKey) {
            return await($this->redis->get($this->key($threadKey)));
        }, $value)) {
            if ($value === '1' || $value === 1 || $value === true) {
                return CommandApprovalMode::Policy;
            }

            return CommandApprovalMode::fromMixed(is_string($value) ? $value : null);
        }

        return CommandApprovalMode::AlwaysApprove;
    }

    /** @deprecated Use setMode(Policy) */
    public function enable(string $threadKey): void
    {
        $this->setMode($threadKey, CommandApprovalMode::Policy);
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
        return $this->getMode($threadKey) !== CommandApprovalMode::AlwaysApprove;
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
