<?php

declare(strict_types=1);

namespace App\Recording;

use App\Repository\SessionRepository;
use App\Storage\DatedStorageLayout;

final class SessionRecorder
{
    /** @var array<int, AsciinemaCastWriter> */
    private array $writers = [];

    /** @var array<int, string> absolute session directories for active recordings */
    private array $activeSessionDirs = [];

    public function __construct(
        private readonly SessionRecordingConfig $config,
        private readonly SessionRepository $sessions,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    public function start(int $sessionId, int $cols, int $rows, string $title, ?string $startTime = null): void
    {
        if (!$this->config->enabled || isset($this->writers[$sessionId])) {
            return;
        }

        $sessionDir = DatedStorageLayout::recordingAbsoluteDir($this->config->storageDir, $sessionId, $startTime);
        $this->activeSessionDirs[$sessionId] = $sessionDir;
        $this->writers[$sessionId] = new AsciinemaCastWriter(
            $sessionDir,
            $sessionId,
            $title,
            $this->config->partMaxBytes,
            $cols,
            $rows,
            DatedStorageLayout::recordingRelative($sessionId, $startTime),
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
        unset($this->activeSessionDirs[$sessionId]);

        try {
            $result = $writer->finish();
            if ($result['parts'] === []) {
                $this->removeSessionDir($sessionId);

                return;
            }

            $this->sessions->setRecordingUrl($sessionId, $result['recording_path']);
        } catch (\Throwable) {
            unset($this->writers[$sessionId]);
            unset($this->activeSessionDirs[$sessionId]);
        }
    }

    public function ensureRecordingAvailable(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): bool
    {
        if (isset($this->writers[$sessionId])) {
            $this->syncManifest($sessionId);
        }

        if ($this->readManifest($sessionId, $recordingUrl, $startTime) !== null) {
            $relative = $recordingUrl;
            if ($relative === null || $relative === '') {
                $relative = DatedStorageLayout::recordingRelative($sessionId, $startTime);
            }
            $this->sessions->setRecordingUrl($sessionId, $relative);

            return true;
        }

        return $this->recoverManifestFromDisk($sessionId, $recordingUrl, $startTime);
    }

    public function abort(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): void
    {
        unset($this->writers[$sessionId]);
        unset($this->activeSessionDirs[$sessionId]);
        $this->removeSessionDir($sessionId, $recordingUrl, $startTime);
    }

    public function sessionDir(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): string
    {
        return $this->resolveSessionDir($sessionId, $recordingUrl, $startTime);
    }

    public function resolvePartPath(
        int $sessionId,
        string $partName,
        ?string $recordingUrl = null,
        ?string $startTime = null,
    ): ?string {
        if (!preg_match('/^part-\d{3}\.cast$/', $partName)) {
            return null;
        }

        $sessionDir = $this->resolveSessionDir($sessionId, $recordingUrl, $startTime);
        $path = $sessionDir . '/' . $partName;
        if (!is_readable($path)) {
            return null;
        }

        $realSession = realpath($sessionDir);
        $realFile = realpath($path);
        if ($realSession === false || $realFile === false || !str_starts_with($realFile, $realSession . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    public function readManifest(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): ?array
    {
        $path = $this->resolveSessionDir($sessionId, $recordingUrl, $startTime) . '/manifest.json';
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

    private function resolveSessionDir(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): string
    {
        if (isset($this->activeSessionDirs[$sessionId])) {
            return $this->activeSessionDirs[$sessionId];
        }

        $existing = DatedStorageLayout::resolveExistingRecordingDir(
            $this->config->storageDir,
            $sessionId,
            $recordingUrl,
            $startTime,
        );
        if ($existing !== null) {
            return $existing;
        }

        return DatedStorageLayout::recordingAbsoluteDir($this->config->storageDir, $sessionId, $startTime);
    }

    private function recoverManifestFromDisk(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): bool
    {
        $dir = $this->resolveSessionDir($sessionId, $recordingUrl, $startTime);
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

        $relative = $recordingUrl;
        if ($relative === null || $relative === '') {
            $relative = DatedStorageLayout::recordingRelative($sessionId, $startTime);
        }
        $this->sessions->setRecordingUrl($sessionId, $relative);

        return true;
    }

    private function removeSessionDir(int $sessionId, ?string $recordingUrl = null, ?string $startTime = null): void
    {
        $dir = $this->resolveSessionDir($sessionId, $recordingUrl, $startTime);
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
