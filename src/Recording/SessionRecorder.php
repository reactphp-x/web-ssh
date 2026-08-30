<?php

declare(strict_types=1);

namespace App\Recording;

use App\Repository\SessionRepository;

final class SessionRecorder
{
    /** @var array<int, AsciinemaCastWriter> */
    private array $writers = [];

    public function __construct(
        private readonly SessionRecordingConfig $config,
        private readonly SessionRepository $sessions,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    public function start(int $sessionId, int $cols, int $rows, string $title): void
    {
        if (!$this->config->enabled || isset($this->writers[$sessionId])) {
            return;
        }

        $sessionDir = $this->sessionDir($sessionId);
        $this->writers[$sessionId] = new AsciinemaCastWriter(
            $sessionDir,
            $sessionId,
            $title,
            $this->config->partMaxBytes,
            $cols,
            $rows,
        );
    }

    public function writeOutput(int $sessionId, string $data): void
    {
        $this->writers[$sessionId]?->writeOutput($data);
    }

    public function writeInput(int $sessionId, string $data): void
    {
        $this->writers[$sessionId]?->writeInput($data);
    }

    public function resize(int $sessionId, int $cols, int $rows): void
    {
        $this->writers[$sessionId]?->resize($cols, $rows);
    }

    public function finish(int $sessionId): void
    {
        $writer = $this->writers[$sessionId] ?? null;
        if ($writer === null) {
            return;
        }

        unset($this->writers[$sessionId]);

        try {
            $result = $writer->finish();
            if ($result['parts'] === []) {
                $this->removeSessionDir($sessionId);

                return;
            }

            $this->sessions->setRecordingUrl($sessionId, $result['recording_path']);
        } catch (\Throwable) {
            unset($this->writers[$sessionId]);
        }
    }

    public function abort(int $sessionId): void
    {
        unset($this->writers[$sessionId]);
        $this->removeSessionDir($sessionId);
    }

    public function sessionDir(int $sessionId): string
    {
        return rtrim($this->config->storageDir, '/') . '/' . $sessionId;
    }

    public function resolvePartPath(int $sessionId, string $partName): ?string
    {
        if (!preg_match('/^part-\d{3}\.cast$/', $partName)) {
            return null;
        }

        $path = $this->sessionDir($sessionId) . '/' . $partName;
        if (!is_readable($path)) {
            return null;
        }

        $realSession = realpath($this->sessionDir($sessionId));
        $realFile = realpath($path);
        if ($realSession === false || $realFile === false || !str_starts_with($realFile, $realSession . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    public function readManifest(int $sessionId): ?array
    {
        $path = $this->sessionDir($sessionId) . '/manifest.json';
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function removeSessionDir(int $sessionId): void
    {
        $dir = $this->sessionDir($sessionId);
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($dir);
    }
}
