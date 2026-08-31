<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Storage\AiSessionStoragePaths;

final class AiSessionLiveTranscript
{
    /** @var array<int, string|null> */
    private array $createdAtCache = [];

    public function __construct(
        private readonly AiSessionStoragePaths $paths,
    ) {
    }

    public function rememberCreatedAt(int $aiSessionId, ?string $createdAt): void
    {
        if ($createdAt !== null && $createdAt !== '') {
            $this->createdAtCache[$aiSessionId] = $createdAt;
        }
    }

    public function append(int $aiSessionId, string $data): void
    {
        if ($data === '') {
            return;
        }

        $path = $this->path($aiSessionId);
        $this->paths->ensureLiveLogDirectory($aiSessionId, $this->createdAt($aiSessionId));
        file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
    }

    public function read(int $aiSessionId): string
    {
        $path = $this->path($aiSessionId);
        if (!is_readable($path)) {
            return '';
        }

        $data = file_get_contents($path);

        return is_string($data) ? $data : '';
    }

    public function clear(int $aiSessionId): void
    {
        $path = $this->path($aiSessionId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function write(int $aiSessionId, string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->paths->ensureLiveLogDirectory($aiSessionId, $this->createdAt($aiSessionId));
        file_put_contents($this->path($aiSessionId), $data, LOCK_EX);
    }

    private function path(int $aiSessionId): string
    {
        return $this->paths->liveLogPath($aiSessionId, $this->createdAt($aiSessionId));
    }

    private function createdAt(int $aiSessionId): ?string
    {
        return $this->createdAtCache[$aiSessionId] ?? null;
    }
}
