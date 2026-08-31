<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\AiSessionStoragePaths;
use App\Storage\DatedStorageLayout;
use PHPUnit\Framework\TestCase;

final class DatedStorageLayoutTest extends TestCase
{
    public function testDatePathUsesTimestamp(): void
    {
        self::assertSame('2026/08/31', DatedStorageLayout::datePath('2026-08-31 10:15:00'));
    }

    public function testRecordingRelativeIncludesDateSegments(): void
    {
        self::assertSame(
            'recordings/2026/08/31/42',
            DatedStorageLayout::recordingRelative(42, '2026-08-31 10:15:00'),
        );
    }

    public function testResolveExistingRecordingDirPrefersLegacyFlatPath(): void
    {
        $root = sys_get_temp_dir() . '/web-ssh-recording-' . bin2hex(random_bytes(4));
        mkdir($root . '/99', 0775, true);
        file_put_contents($root . '/99/manifest.json', '{}');

        try {
            $resolved = DatedStorageLayout::resolveExistingRecordingDir($root, 99, null, '2026-08-31 10:00:00');
            self::assertSame($root . '/99', $resolved);
        } finally {
            unlink($root . '/99/manifest.json');
            rmdir($root . '/99');
            rmdir($root);
        }
    }

    public function testResolveExistingRecordingDirFindsDatedPath(): void
    {
        $root = sys_get_temp_dir() . '/web-ssh-recording-' . bin2hex(random_bytes(4));
        mkdir($root . '/2026/08/31/77', 0775, true);
        file_put_contents($root . '/2026/08/31/77/manifest.json', '{}');

        try {
            $resolved = DatedStorageLayout::resolveExistingRecordingDir(
                $root,
                77,
                'recordings/2026/08/31/77',
                null,
            );
            self::assertSame($root . '/2026/08/31/77', $resolved);
        } finally {
            unlink($root . '/2026/08/31/77/manifest.json');
            rmdir($root . '/2026/08/31/77');
            rmdir($root . '/2026/08/31');
            rmdir($root . '/2026/08');
            rmdir($root . '/2026');
            rmdir($root);
        }
    }
}

final class AiSessionStoragePathsTest extends TestCase
{
    public function testLiveLogUsesLegacyPathWhenPresent(): void
    {
        $base = sys_get_temp_dir() . '/web-ssh-ai-paths-' . bin2hex(random_bytes(4));
        mkdir($base . '/live', 0775, true);
        file_put_contents($base . '/live/13.log', 'legacy');

        try {
            $paths = new AiSessionStoragePaths($base);
            self::assertSame($base . '/live/13.log', $paths->liveLogPath(13));
        } finally {
            unlink($base . '/live/13.log');
            rmdir($base . '/live');
            rmdir($base);
        }
    }

    public function testChatDirectoryUsesLegacyRootWhenPresent(): void
    {
        $base = sys_get_temp_dir() . '/web-ssh-ai-paths-' . bin2hex(random_bytes(4));
        mkdir($base, 0775, true);
        file_put_contents($base . '/neuron_5.chat', '{}');

        try {
            $paths = new AiSessionStoragePaths($base);
            self::assertSame($base, $paths->chatDirectory(5));
        } finally {
            unlink($base . '/neuron_5.chat');
            rmdir($base);
        }
    }
}
