<?php

declare(strict_types=1);

namespace App\Storage;

final class AiSessionStoragePaths
{
    public function __construct(
        private readonly string $aiSessionsBase,
    ) {
    }

    public function chatDirectory(int $aiSessionId, ?string $createdAt = null): string
    {
        $legacyFile = $this->legacyChatFile($aiSessionId);
        if (is_file($legacyFile)) {
            return $this->aiSessionsBase;
        }

        $datedDir = $this->datedRoot($createdAt);
        if (is_file($datedDir . '/neuron_' . $aiSessionId . '.chat')) {
            return $datedDir;
        }

        return $datedDir;
    }

    public function liveLogPath(int $aiSessionId, ?string $createdAt = null): string
    {
        $legacy = $this->legacyLiveLogPath($aiSessionId);
        if (is_file($legacy)) {
            return $legacy;
        }

        $dated = $this->datedLiveLogPath($aiSessionId, $createdAt);
        if (is_file($dated)) {
            return $dated;
        }

        return $dated;
    }

    public function ensureLiveLogDirectory(int $aiSessionId, ?string $createdAt = null): void
    {
        $path = $this->liveLogPath($aiSessionId, $createdAt);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create live transcript directory: ' . $dir);
        }
    }

    private function datedRoot(?string $createdAt = null): string
    {
        return rtrim($this->aiSessionsBase, '/') . '/' . DatedStorageLayout::datePath($createdAt);
    }

    private function legacyChatFile(int $aiSessionId): string
    {
        return rtrim($this->aiSessionsBase, '/') . '/neuron_' . $aiSessionId . '.chat';
    }

    private function legacyLiveLogPath(int $aiSessionId): string
    {
        return rtrim($this->aiSessionsBase, '/') . '/live/' . $aiSessionId . '.log';
    }

    private function datedLiveLogPath(int $aiSessionId, ?string $createdAt = null): string
    {
        return $this->datedRoot($createdAt) . '/live/' . $aiSessionId . '.log';
    }
}
