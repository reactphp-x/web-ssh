<?php

declare(strict_types=1);

namespace App\Ssh;

final class AiSessionLiveTranscript
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function append(int $aiSessionId, string $data): void
    {
        if ($data === '') {
            return;
        }

        $path = $this->path($aiSessionId);
        $this->ensureDirectory();
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

        $this->ensureDirectory();
        file_put_contents($this->path($aiSessionId), $data, LOCK_EX);
    }

    private function path(int $aiSessionId): string
    {
        return $this->directory . '/' . $aiSessionId . '.log';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        mkdir($this->directory, 0755, true);
    }
}
