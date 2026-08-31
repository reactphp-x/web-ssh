<?php

declare(strict_types=1);

namespace App\Recording;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use RuntimeException;

final class AsciinemaCastWriter
{
    private const FLUSH_DELAY = 0.05;

    private const MAX_BUFFER_BYTES = 524288;

    private float $startedAt;

    private float $lastEventAt;

    /** @var resource|null */
    private $handle = null;

    private int $partIndex = 0;

    private int $partBytes = 0;

    private int $eventCount = 0;

    /** @var list<array{name: string, bytes: int, events: int}> */
    private array $parts = [];

    private int $cols;

    private int $rows;

    private string $outputBuffer = '';

    private float $outputBufferStartAt = 0.0;

    private float $outputBufferEndAt = 0.0;

    private bool $flushScheduled = false;

    private ?TimerInterface $flushTimer = null;

    public function __construct(
        private readonly string $sessionDir,
        private readonly int $sessionId,
        private readonly string $title,
        private readonly int $partMaxBytes,
        int $cols,
        int $rows,
        private readonly string $recordingPathRelative,
    ) {
        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);
        $this->startedAt = microtime(true);
        $this->lastEventAt = $this->startedAt;
        $this->ensureSessionDir();
        $this->openPart();
    }

    public function writeOutput(string $data): void
    {
        if ($data === '') {
            return;
        }

        $now = microtime(true);
        if ($this->outputBuffer === '') {
            $this->outputBufferStartAt = $now;
        }

        $this->outputBuffer .= $data;
        $this->outputBufferEndAt = $now;

        if (strlen($this->outputBuffer) >= self::MAX_BUFFER_BYTES) {
            $this->cancelFlushTimer();
            $this->flushOutputBuffer(force: true);

            return;
        }

        $this->scheduleFlush();
    }

    /**
     * Input is not recorded: xterm sends focus/mouse/OSC noise that stalls replay,
     * and echoed keystrokes already appear in output events.
     */
    public function writeInput(string $data): void
    {
    }

    public function resize(int $cols, int $rows): void
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        if ($cols === $this->cols && $rows === $this->rows) {
            return;
        }

        $this->flushOutputBuffer(force: true);

        $this->cols = $cols;
        $this->rows = $rows;

        $now = microtime(true);
        $this->lastEventAt = $now;
        $this->appendEvent('r', $cols . 'x' . $rows);
    }

    /**
     * @return array{recording_path: string, parts: list<array{name: string, bytes: int, events: int}>}
     */
    /**
     * Flush pending output and refresh manifest.json without closing the cast writer.
     *
     * @return array{recording_path: string, parts: list<array{name: string, bytes: int, events: int}>}
     */
    public function syncManifest(): array
    {
        $this->cancelFlushTimer();
        $this->flushOutputBuffer(force: true);

        return $this->writeManifestFile();
    }

    /**
     * @return array{recording_path: string, parts: list<array{name: string, bytes: int, events: int}>}
     */
    public function finish(): array
    {
        $this->cancelFlushTimer();
        $this->flushOutputBuffer(force: true);
        $this->closePart();

        return $this->writeManifestFile();
    }

    /**
     * @return array{recording_path: string, parts: list<array{name: string, bytes: int, events: int}>}
     */
    private function writeManifestFile(): array
    {
        $manifest = [
            'version' => 1,
            'session_id' => $this->sessionId,
            'title' => $this->title,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'started_at_unix' => (int) floor($this->startedAt),
            'parts' => $this->parts,
        ];
        $encoded = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode recording manifest.');
        }

        if (file_put_contents($this->sessionDir . '/manifest.json', $encoded) === false) {
            throw new RuntimeException('Failed to write recording manifest.');
        }

        return [
            'recording_path' => $this->recordingPathRelative,
            'parts' => $this->parts,
        ];
    }

    private function scheduleFlush(): void
    {
        if ($this->flushTimer !== null) {
            Loop::cancelTimer($this->flushTimer);
        }

        $this->flushScheduled = true;
        $this->flushTimer = Loop::addTimer(self::FLUSH_DELAY, function (): void {
            $this->flushScheduled = false;
            $this->flushTimer = null;
            $this->flushOutputBuffer();
        });
    }

    private function cancelFlushTimer(): void
    {
        if ($this->flushTimer !== null) {
            Loop::cancelTimer($this->flushTimer);
            $this->flushTimer = null;
        }

        $this->flushScheduled = false;
    }

    private function flushOutputBuffer(bool $force = false): void
    {
        if ($this->outputBuffer === '') {
            return;
        }

        if (!$force && $this->hasOpenSyncRegion()) {
            $this->scheduleFlush();

            return;
        }

        $payload = $this->outputBuffer;
        $endAt = $this->outputBufferEndAt;
        $this->outputBuffer = '';

        $this->lastEventAt = $endAt;
        $this->appendEvent('o', $payload);
    }

    private function hasOpenSyncRegion(): bool
    {
        $opens = substr_count($this->outputBuffer, "\033[?2026h");
        $closes = substr_count($this->outputBuffer, "\033[?2026l");

        return $opens > $closes;
    }

    private function ensureSessionDir(): void
    {
        if (is_dir($this->sessionDir)) {
            return;
        }

        if (!mkdir($this->sessionDir, 0775, true) && !is_dir($this->sessionDir)) {
            throw new RuntimeException('Failed to create recording directory.');
        }
    }

    private function openPart(): void
    {
        $this->partIndex++;
        $name = sprintf('part-%03d.cast', $this->partIndex);
        $path = $this->sessionDir . '/' . $name;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open recording part file.');
        }

        $header = json_encode([
            'version' => 2,
            'width' => $this->cols,
            'height' => $this->rows,
            'timestamp' => (int) floor($this->startedAt),
            'title' => $this->title,
            'env' => [
                'SHELL' => '/bin/bash',
                'TERM' => 'xterm-256color',
            ],
        ], JSON_UNESCAPED_UNICODE);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('Failed to encode cast header.');
        }

        $line = $header . "\n";
        if (fwrite($handle, $line) === false) {
            fclose($handle);
            throw new RuntimeException('Failed to write cast header.');
        }

        $this->handle = $handle;
        $this->partBytes = strlen($line);
        $this->eventCount = 0;
        $this->parts[] = [
            'name' => $name,
            'bytes' => $this->partBytes,
            'events' => 0,
        ];
    }

    private function closePart(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        fclose($this->handle);
        $this->handle = null;
    }

    private function appendEvent(string $type, string $payload): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        $timestamp = max(0.0, $this->lastEventAt - $this->startedAt);
        $line = json_encode(
            [round($timestamp, 6), $type, $payload],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($line === false) {
            return;
        }

        $line .= "\n";
        if (fwrite($this->handle, $line) === false) {
            return;
        }

        $this->partBytes += strlen($line);
        $this->eventCount++;
        $index = count($this->parts) - 1;
        $this->parts[$index]['bytes'] = $this->partBytes;
        $this->parts[$index]['events'] = $this->eventCount;

        if ($this->partBytes >= $this->partMaxBytes) {
            $this->closePart();
            $this->openPart();
        }
    }
}
