<?php

declare(strict_types=1);

namespace App\Tests;

use App\Recording\AsciinemaCastWriter;
use PHPUnit\Framework\TestCase;

final class AsciinemaCastWriterTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/web-ssh-cast-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpdir)) {
            return;
        }

        foreach (glob($this->tmpdir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpdir);
    }

    public function testSyncManifestKeepsWriterOpen(): void
    {
        $writer = new AsciinemaCastWriter($this->tmpdir, 11, 'sync-demo', 1024, 80, 24, 'recordings/test/11');
        $writer->writeOutput('first');

        $sync = $writer->syncManifest();

        self::assertSame('recordings/test/11', $sync['recording_path']);
        self::assertFileExists($this->tmpdir . '/manifest.json');

        $writer->writeOutput(' second');
        $result = $writer->finish();

        self::assertCount(1, $result['parts']);
        $cast = implode("\n", file($this->tmpdir . '/part-001.cast', FILE_IGNORE_NEW_LINES));
        self::assertStringContainsString('first', $cast);
        self::assertStringContainsString(' second', $cast);
    }

    public function testWritesCastFileAndManifest(): void
    {
        $writer = new AsciinemaCastWriter($this->tmpdir, 42, 'demo-host', 1024, 80, 24, 'recordings/test/42');
        $writer->writeOutput('hello');
        $writer->writeInput('ls');
        usleep(2000);
        $writer->resize(100, 30);
        $writer->writeOutput(' world');

        $result = $writer->finish();

        self::assertSame('recordings/test/42', $result['recording_path']);
        self::assertCount(1, $result['parts']);
        self::assertFileExists($this->tmpdir . '/part-001.cast');
        self::assertFileExists($this->tmpdir . '/manifest.json');

        $lines = file($this->tmpdir . '/part-001.cast', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        array_shift($lines);

        $times = [];
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            self::assertIsArray($event);
            $times[] = (float) $event[0];
        }

        self::assertGreaterThanOrEqual(2, count($times));
        for ($index = 1; $index < count($times); $index++) {
            self::assertGreaterThanOrEqual($times[$index - 1], $times[$index]);
        }
        self::assertGreaterThan($times[0], $times[count($times) - 1]);

        $cast = implode("\n", file($this->tmpdir . '/part-001.cast', FILE_IGNORE_NEW_LINES));
        self::assertStringContainsString('"version":2', $cast);
        self::assertStringContainsString('"o"', $cast);
        self::assertStringContainsString('hello', $cast);
        self::assertStringContainsString('"r"', $cast);
        self::assertStringNotContainsString('"i"', $cast);

        $manifest = json_decode((string) file_get_contents($this->tmpdir . '/manifest.json'), true);
        self::assertSame(42, $manifest['session_id']);
        self::assertSame('demo-host', $manifest['title']);
    }

    public function testRotatesPartsWhenSizeLimitReached(): void
    {
        $writer = new AsciinemaCastWriter($this->tmpdir, 7, 'big', 120, 80, 24, 'recordings/test/7');
        $writer->writeOutput(str_repeat('x', 200));
        $writer->writeOutput(str_repeat('y', 200));

        $result = $writer->finish();

        self::assertGreaterThanOrEqual(2, count($result['parts']));
        self::assertFileExists($this->tmpdir . '/part-001.cast');
        self::assertFileExists($this->tmpdir . '/part-002.cast');
    }

    public function testKeepsOpenSyncOutputInSingleEvent(): void
    {
        $writer = new AsciinemaCastWriter($this->tmpdir, 9, 'btop', 1024 * 1024, 180, 76, 'recordings/test/9');
        $writer->writeOutput("\033[?2026h" . str_repeat('x', 1000));
        $writer->writeOutput(str_repeat('y', 1000));
        $writer->writeOutput(str_repeat('z', 1000) . "\033[?2026l");

        $result = $writer->finish();

        self::assertCount(1, $result['parts']);
        $lines = file($this->tmpdir . '/part-001.cast', FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
    }
}
