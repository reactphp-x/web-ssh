<?php

declare(strict_types=1);

namespace App\Log;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use ReactX\Worker\Worker;

final class LogRotationMonitor
{
    private ?TimerInterface $timer = null;

    private ?string $lastRotatedDate = null;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxFiles = 14,
        private readonly float $checkInterval = 3600.0,
    ) {
        if ($this->maxFiles < 1) {
            throw new \InvalidArgumentException('Log rotation max files must be at least one.');
        }
        if ($this->checkInterval <= 0) {
            throw new \InvalidArgumentException('Log rotation interval must be greater than zero.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s".', $this->directory));
        }
    }

    public function start(): void
    {
        if ($this->timer !== null) {
            return;
        }

        $this->rotateStaleLogs();
        $this->lastRotatedDate = date('Y-m-d');

        $this->timer = Loop::addPeriodicTimer($this->checkInterval, function (): void {
            try {
                $this->rotateIfNeeded();
            } catch (\Throwable $exception) {
                Worker::log(sprintf('Log rotation error: %s', $exception->getMessage()));
            }
        });

        Worker::log(sprintf(
            'Log rotation monitor started for %s (max %d files, interval %.0fs)',
            $this->directory,
            $this->maxFiles,
            $this->checkInterval,
        ));
    }

    public function stop(): void
    {
        if ($this->timer === null) {
            return;
        }

        Loop::cancelTimer($this->timer);
        $this->timer = null;
    }

    private function rotateIfNeeded(): void
    {
        $today = date('Y-m-d');
        if ($this->lastRotatedDate === $today) {
            return;
        }

        $archiveDate = $this->lastRotatedDate ?? $today;

        foreach ($this->discoverActiveLogs() as $basename) {
            $this->rotateLog($basename, $archiveDate);
            $this->pruneArchives($basename);
        }

        $this->lastRotatedDate = $today;

        Worker::log(sprintf('Rotated logs in %s for %s', $this->directory, $archiveDate));
    }

    private function rotateStaleLogs(): void
    {
        $todayStart = strtotime('today');

        foreach ($this->discoverActiveLogs() as $basename) {
            $activePath = $this->directory . DIRECTORY_SEPARATOR . $basename . '.log';
            if (!is_file($activePath) || filesize($activePath) === 0) {
                continue;
            }

            clearstatcache(true, $activePath);
            $modifiedAt = filemtime($activePath) ?: $todayStart;
            if ($modifiedAt >= $todayStart) {
                continue;
            }

            $archiveDate = date('Y-m-d', $modifiedAt);
            $this->rotateLog($basename, $archiveDate);
            $this->pruneArchives($basename);
        }
    }

    /**
     * @return list<string>
     */
    private function discoverActiveLogs(): array
    {
        $basenames = [];
        $pattern = $this->directory . DIRECTORY_SEPARATOR . '*.log';

        foreach (glob($pattern) ?: [] as $path) {
            $filename = basename($path);
            if (preg_match('/^.+-\d{4}-\d{2}-\d{2}\.log$/', $filename) === 1) {
                continue;
            }

            $basenames[] = pathinfo($filename, PATHINFO_FILENAME);
        }

        sort($basenames);

        return $basenames;
    }

    private function rotateLog(string $basename, string $archiveDate): void
    {
        $activePath = $this->directory . DIRECTORY_SEPARATOR . $basename . '.log';
        if (!is_file($activePath) || filesize($activePath) === 0) {
            return;
        }

        $archivePath = $this->directory . DIRECTORY_SEPARATOR . $basename . '-' . $archiveDate . '.log';
        if (is_file($archivePath)) {
            file_put_contents($archivePath, (string) file_get_contents($activePath), FILE_APPEND);
            unlink($activePath);

            return;
        }

        rename($activePath, $archivePath);
    }

    private function pruneArchives(string $basename): void
    {
        $pattern = $this->directory . DIRECTORY_SEPARATOR . $basename . '-*.log';
        $files = glob($pattern) ?: [];

        usort($files, static fn (string $left, string $right): int => strcmp($right, $left));

        foreach (array_slice($files, $this->maxFiles) as $oldFile) {
            unlink($oldFile);
        }
    }
}
