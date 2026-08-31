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

    public function syncManifest(int $sessionId): void
    {
        $writer = $this->writers[$sessionId] ?? null;
        if ($writer === null) {
            return;
        }

        try {
            $result = $writer->syncManifest();
            if ($result['parts'] === []) {
                return;
            }

            $this->sessions->setRecordingUrl($sessionId, $result['recording_path']);
        } catch (\Throwable) {
        }
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

    public function ensureRecordingAvailable(int $sessionId): bool
    {
        if (isset($this->writers[$sessionId])) {
            $this->syncManifest($sessionId);
        }

        if ($this->readManifest($sessionId) !== null) {
            $this->sessions->setRecordingUrl($sessionId, 'recordings/' . $sessionId);

            return true;
        }

        return $this->recoverManifestFromDisk($sessionId);
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

    private function recoverManifestFromDisk(int $sessionId): bool
    {
        $dir = $this->sessionDir($sessionId);
        $files = glob($dir . '/part-*.cast') ?: [];
        if ($files === []) {
            return false;
        }

        sort($files, SORT_STRING);
        $firstLine = @file($files[0], FILE_IGNORE_NEW_LINES)[0] ?? '';
        $header = json_decode($firstLine, true);
        if (!is_array($header)) {
            return false;
        }

        $parts = [];
        foreach ($files as $path) {
            $name = basename($path);
            $bytes = filesize($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            $events = max(0, count($lines) - 1);
            $parts[] = [
                'name' => $name,
                'bytes' => $bytes === false ? 0 : $bytes,
                'events' => $events,
            ];
        }

        $manifest = [
            'version' => 1,
            'session_id' => $sessionId,
            'title' => (string) ($header['title'] ?? ('session-' . $sessionId)),
            'cols' => (int) ($header['width'] ?? 120),
            'rows' => (int) ($header['height'] ?? 40),
            'started_at_unix' => (int) ($header['timestamp'] ?? time()),
            'parts' => $parts,
        ];
        $encoded = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return false;
        }

        if (file_put_contents($dir . '/manifest.json', $encoded) === false) {
            return false;
        }

        $this->sessions->setRecordingUrl($sessionId, 'recordings/' . $sessionId);

        return true;
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
