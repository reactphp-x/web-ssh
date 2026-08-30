<?php

declare(strict_types=1);

namespace App\Ssh;

use FFI;
use React\Stream\DuplexStreamInterface;
use RuntimeException;
use Throwable;

final class PtySize
{
    private const CDEF = <<<'C'
        struct winsize {
            unsigned short ws_row;
            unsigned short ws_col;
            unsigned short ws_xpixel;
            unsigned short ws_ypixel;
        };
        int ioctl(int fd, unsigned long request, ...);
    C;

    private static ?FFI $ffi = null;

    public static function set(DuplexStreamInterface $pty, int $rows, int $cols): void
    {
        $rows = max(1, $rows);
        $cols = max(1, $cols);

        $stream = self::underlyingStream($pty);
        if ($stream === null) {
            return;
        }

        if (\function_exists('ioctl')) {
            $request = self::tioCsWinSz();
            $payload = ['rows' => $rows, 'cols' => $cols, 'x' => 0, 'y' => 0];
            if (@ioctl($stream, $request, $payload) === 0) {
                return;
            }
        }

        if (!\extension_loaded('FFI') || !\in_array(\PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            return;
        }

        $fd = self::streamToFd($stream);
        if ($fd === null) {
            return;
        }

        try {
            if (self::ffi()->ioctl($fd, self::tioCsWinSz(), FFI::addr(self::winsize($rows, $cols))) !== 0) {
                throw new RuntimeException(sprintf('Failed to set PTY size to %dx%d.', $cols, $rows));
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(sprintf('Failed to set PTY size to %dx%d.', $cols, $rows), 0, $exception);
        }
    }

    private static function winsize(int $rows, int $cols): FFI\CData
    {
        $winsize = self::ffi()->new('struct winsize');
        $winsize->ws_row = $rows;
        $winsize->ws_col = $cols;
        $winsize->ws_xpixel = 0;
        $winsize->ws_ypixel = 0;

        return $winsize;
    }

    private static function ffi(): FFI
    {
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            foreach (['libutil.so.1', 'libutil.so', null] as $library) {
                try {
                    self::$ffi = $library === null
                        ? FFI::cdef(self::CDEF)
                        : FFI::cdef(self::CDEF, $library);

                    return self::$ffi;
                } catch (Throwable) {
                    continue;
                }
            }
        }

        self::$ffi = FFI::cdef(self::CDEF, '/usr/lib/libc.dylib');

        return self::$ffi;
    }

    private static function tioCsWinSz(): int
    {
        return \PHP_OS_FAMILY === 'Darwin' ? 0x80087467 : 0x5414;
    }

    /**
     * @return resource|null
     */
    private static function underlyingStream(DuplexStreamInterface $pty): mixed
    {
        $reflection = new \ReflectionObject($pty);
        if (!$reflection->hasProperty('stream')) {
            return null;
        }

        $property = $reflection->getProperty('stream');
        $property->setAccessible(true);
        $stream = $property->getValue($pty);

        return \is_resource($stream) ? $stream : null;
    }

    /**
     * @param resource $stream
     */
    private static function streamToFd(mixed $stream): ?int
    {
        $stat = fstat($stream);
        if ($stat === false) {
            return null;
        }

        for ($fd = 0; $fd < 256; $fd++) {
            $path = '/dev/fd/' . $fd;
            if (!@is_readable($path)) {
                continue;
            }

            $candidate = @stat($path);
            if ($candidate !== false
                && $candidate['ino'] === $stat['ino']
                && $candidate['dev'] === $stat['dev']
            ) {
                return $fd;
            }
        }

        return null;
    }
}
