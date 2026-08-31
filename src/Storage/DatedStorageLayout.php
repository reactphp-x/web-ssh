<?php

declare(strict_types=1);

namespace App\Storage;

final class DatedStorageLayout
{
    public static function datePath(?string $timestamp = null): string
    {
        if ($timestamp !== null && $timestamp !== '') {
            $parsed = strtotime($timestamp);
            if ($parsed !== false) {
                return date('Y/m/d', $parsed);
            }
        }

        return date('Y/m/d');
    }

    public static function recordingRelative(int $sessionId, ?string $timestamp = null): string
    {
        return 'recordings/' . self::datePath($timestamp) . '/' . $sessionId;
    }

    public static function recordingAbsoluteDir(string $storageDir, int $sessionId, ?string $timestamp = null): string
    {
        return rtrim($storageDir, '/') . '/' . self::datePath($timestamp) . '/' . $sessionId;
    }

    public static function legacyRecordingDir(string $storageDir, int $sessionId): string
    {
        return rtrim($storageDir, '/') . '/' . $sessionId;
    }

    /**
     * @return list<string>
     */
    public static function recordingDirCandidates(
        string $storageDir,
        int $sessionId,
        ?string $recordingUrl = null,
        ?string $startTime = null,
    ): array {
        $candidates = [];

        if ($recordingUrl !== null && $recordingUrl !== '') {
            $suffix = preg_replace('#^recordings/#', '', $recordingUrl) ?? '';
            $suffix = ltrim((string) $suffix, '/');
            if ($suffix !== '') {
                $candidates[] = rtrim($storageDir, '/') . '/' . $suffix;
            }
        }

        if ($startTime !== null && $startTime !== '') {
            $candidates[] = self::recordingAbsoluteDir($storageDir, $sessionId, $startTime);
        }

        $candidates[] = self::legacyRecordingDir($storageDir, $sessionId);
        $candidates[] = self::recordingAbsoluteDir($storageDir, $sessionId);

        $unique = [];
        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    public static function resolveExistingRecordingDir(
        string $storageDir,
        int $sessionId,
        ?string $recordingUrl = null,
        ?string $startTime = null,
    ): ?string {
        foreach (self::recordingDirCandidates($storageDir, $sessionId, $recordingUrl, $startTime) as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
