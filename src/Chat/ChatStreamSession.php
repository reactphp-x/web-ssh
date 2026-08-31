<?php

declare(strict_types=1);

namespace App\Chat;

use App\Neuron\HttpClient\HttpStreamScope;
use ReactphpX\Redis\Pool as RedisPool;
use Throwable;

use function React\Async\await;

/**
 * Tracks in-flight SSE generation per thread so clients can reconnect or stop cooperatively.
 *
 * Meta/events are mirrored to Redis so subscribe/stop work across HTTP workers.
 * HttpStreamScope remains process-local; cross-worker stop relies on Redis stop flag + polling.
 */
final class ChatStreamSession
{
    private const TTL = 300;

    /** @var array<string, HttpStreamScope> */
    private static array $scopes = [];

    /** @var array<string, array{active: bool, manual_stop: bool, thread_key: string, user_message: string, partial: string, events_count: int}> */
    private static array $memoryMeta = [];

    /** @var array<string, list<array{event: string, data: array<string, mixed>}>> */
    private static array $memoryEvents = [];

    public function __construct(
        private readonly ?RedisPool $redis = null,
    ) {
    }

    public function begin(string $sessionKey, string $threadKey, string $userMessage): void
    {
        $meta = [
            'active' => true,
            'manual_stop' => false,
            'thread_key' => $threadKey,
            'user_message' => $userMessage,
            'partial' => '',
            'events_count' => 0,
        ];

        self::$memoryMeta[$sessionKey] = $meta;
        self::$memoryEvents[$sessionKey] = [];
        $this->clearStopFlag($sessionKey);
        $this->writeMeta($sessionKey, $meta);
        $this->clearRedisEvents($sessionKey);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function append(string $sessionKey, string $event, array $data): void
    {
        $payload = ['event' => $event, 'data' => $data];

        self::$memoryEvents[$sessionKey] ??= [];
        self::$memoryEvents[$sessionKey][] = $payload;

        $meta = self::$memoryMeta[$sessionKey] ?? $this->readMetaFromRedis($sessionKey);
        if ($meta !== null) {
            if ($event === 'delta' && isset($data['text']) && is_string($data['text'])) {
                $meta['partial'] = (string) ($meta['partial'] ?? '') . $data['text'];
            }
            $meta['events_count'] = count(self::$memoryEvents[$sessionKey]);
            self::$memoryMeta[$sessionKey] = $meta;
            $this->writeMeta($sessionKey, $meta);
        }

        $this->appendEvent($sessionKey, $payload);
    }

    public function registerScope(string $sessionKey, HttpStreamScope $scope): void
    {
        self::$scopes[$sessionKey] = $scope;
    }

    public function unregisterScope(string $sessionKey): void
    {
        unset(self::$scopes[$sessionKey]);
    }

    public function requestManualStop(string $sessionKey): void
    {
        $meta = $this->readMeta($sessionKey);
        if ($meta === null) {
            return;
        }
        $meta['manual_stop'] = true;
        $this->setStopFlag($sessionKey);
        $this->storeMeta($sessionKey, $meta);
        $this->closeScope($sessionKey);
    }

    public function shouldStop(string $sessionKey): bool
    {
        if ($this->hasStopFlag($sessionKey)) {
            return true;
        }

        $meta = $this->readMeta($sessionKey);

        return $meta !== null && ($meta['manual_stop'] ?? false) === true;
    }

    public function wasManualStop(string $sessionKey): bool
    {
        return $this->shouldStop($sessionKey);
    }

    public function isActive(string $sessionKey): bool
    {
        $meta = $this->readMeta($sessionKey);

        return $meta !== null && ($meta['active'] ?? false) === true;
    }

    public function finish(string $sessionKey): void
    {
        $this->unregisterScope($sessionKey);

        $meta = self::$memoryMeta[$sessionKey] ?? $this->readMetaFromRedis($sessionKey);
        if ($meta !== null) {
            $meta['active'] = false;
            self::$memoryMeta[$sessionKey] = $meta;
            $this->writeMeta($sessionKey, $meta, self::TTL);
        }

        $this->clearStopFlag($sessionKey);
    }

    public function isSubscribeAllowed(string $sessionKey): bool
    {
        return $this->isActive($sessionKey) || $this->hasBufferedEvents($sessionKey);
    }

    public function isManuallyStopped(string $sessionKey): bool
    {
        $meta = $this->readMeta($sessionKey);

        return $meta !== null && ($meta['manual_stop'] ?? false) === true;
    }

    public function isStreamComplete(string $sessionKey): bool
    {
        $events = $this->readEvents($sessionKey);
        if ($events === []) {
            return true;
        }

        $last = $events[array_key_last($events)];

        return ($last['event'] ?? '') === 'done' || ($last['event'] ?? '') === 'error';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMeta(string $sessionKey): ?array
    {
        $meta = $this->readMeta($sessionKey);
        if ($meta === null || ($meta['active'] ?? false) !== true) {
            return null;
        }

        return [
            'active' => true,
            'manual_stop' => (bool) ($meta['manual_stop'] ?? false),
            'thread_key' => (string) ($meta['thread_key'] ?? ''),
            'user_message' => (string) ($meta['user_message'] ?? ''),
            'partial' => (string) ($meta['partial'] ?? ''),
            'events_count' => (int) ($meta['events_count'] ?? 0),
        ];
    }

    public function eventCount(string $sessionKey): int
    {
        return count($this->readEvents($sessionKey));
    }

    public function hasBufferedEvents(string $sessionKey): bool
    {
        return $this->eventCount($sessionKey) > 0;
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    public function eventsSince(string $sessionKey, int $fromIndex): array
    {
        $events = $this->readEvents($sessionKey);

        return array_values(array_slice($events, $fromIndex));
    }

    public function closeScope(string $sessionKey): void
    {
        $scope = self::$scopes[$sessionKey] ?? null;
        if ($scope !== null) {
            $scope->closeAll();
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function storeMeta(string $sessionKey, array $meta, ?int $ttl = null): void
    {
        self::$memoryMeta[$sessionKey] = $meta;
        $this->writeMeta($sessionKey, $meta, $ttl);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $sessionKey, array $meta, ?int $ttl = null): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($ttl !== null) {
                await($this->redis->set($this->metaKey($sessionKey), $json, 'EX', $ttl));
            } else {
                await($this->redis->set($this->metaKey($sessionKey), $json, 'EX', self::TTL));
            }
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMeta(string $sessionKey): ?array
    {
        $memory = self::$memoryMeta[$sessionKey] ?? null;
        $remote = $this->readMetaFromRedis($sessionKey);

        if ($memory === null) {
            return $remote;
        }
        if ($remote === null) {
            return $memory;
        }

        return [
            'active' => ($memory['active'] ?? false) || ($remote['active'] ?? false),
            'manual_stop' => ($memory['manual_stop'] ?? false) || ($remote['manual_stop'] ?? false),
            'thread_key' => (string) ($memory['thread_key'] ?? $remote['thread_key'] ?? ''),
            'user_message' => (string) ($memory['user_message'] ?? $remote['user_message'] ?? ''),
            'partial' => $this->longerText(
                (string) ($memory['partial'] ?? ''),
                (string) ($remote['partial'] ?? ''),
            ),
            'events_count' => max((int) ($memory['events_count'] ?? 0), (int) ($remote['events_count'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMetaFromRedis(string $sessionKey): ?array
    {
        if ($this->redis === null) {
            return null;
        }
        try {
            $raw = await($this->redis->get($this->metaKey($sessionKey)));
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * @param array{event: string, data: array<string, mixed>} $payload
     */
    private function appendEvent(string $sessionKey, array $payload): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            await($this->redis->rPush($this->eventsKey($sessionKey), $json));
            await($this->redis->expire($this->eventsKey($sessionKey), self::TTL));
        } catch (Throwable) {
        }
    }

    private function clearRedisEvents(string $sessionKey): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            await($this->redis->del($this->eventsKey($sessionKey)));
        } catch (Throwable) {
        }
    }

    private function clearRedisMeta(string $sessionKey): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            await($this->redis->del($this->metaKey($sessionKey)));
        } catch (Throwable) {
        }
    }

    private function setStopFlag(string $sessionKey): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            await($this->redis->set($this->stopKey($sessionKey), '1', 'EX', self::TTL));
        } catch (Throwable) {
        }
    }

    private function hasStopFlag(string $sessionKey): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            return await($this->redis->get($this->stopKey($sessionKey))) === '1';
        } catch (Throwable) {
            return false;
        }
    }

    private function clearStopFlag(string $sessionKey): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            await($this->redis->del($this->stopKey($sessionKey)));
        } catch (Throwable) {
        }
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function readEvents(string $sessionKey): array
    {
        $memory = self::$memoryEvents[$sessionKey] ?? [];
        $remote = $this->readEventsFromRedis($sessionKey);

        if ($remote === []) {
            return $memory;
        }
        if ($memory === []) {
            return $remote;
        }

        return count($remote) >= count($memory) ? $remote : $memory;
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function readEventsFromRedis(string $sessionKey): array
    {
        if ($this->redis === null) {
            return [];
        }

        try {
            $rows = await($this->redis->lRange($this->eventsKey($sessionKey), 0, -1));
            if (! is_array($rows) || $rows === []) {
                return [];
            }
            $events = [];
            foreach ($rows as $row) {
                if (! is_string($row) || $row === '') {
                    continue;
                }
                $decoded = json_decode($row, true);
                if (is_array($decoded) && isset($decoded['event'], $decoded['data']) && is_array($decoded['data'])) {
                    $events[] = [
                        'event' => (string) $decoded['event'],
                        'data' => $decoded['data'],
                    ];
                }
            }

            return $events;
        } catch (Throwable) {
            return [];
        }
    }

    private function longerText(string $left, string $right): string
    {
        return strlen($left) >= strlen($right) ? $left : $right;
    }

    private function metaKey(string $sessionKey): string
    {
        return 'neuron-chat:stream:meta:' . sha1($sessionKey);
    }

    private function eventsKey(string $sessionKey): string
    {
        return 'neuron-chat:stream:events:' . sha1($sessionKey);
    }

    private function stopKey(string $sessionKey): string
    {
        return 'neuron-chat:stream:stop:' . sha1($sessionKey);
    }
}
