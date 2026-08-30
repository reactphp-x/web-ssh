<?php

declare(strict_types=1);

namespace App\Ssh;

final class CommandOutputCollector
{
    public static function stripAnsi(string $text): string
    {
        $text = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $text) ?? $text;
        $text = preg_replace('/\x1b\][^\x07]*(?:\x07|\x1b\\\\)/', '', $text) ?? $text;
        $text = preg_replace('/\x1b[@-_]/', '', $text) ?? $text;

        return self::sanitizeUtf8($text);
    }

    public static function sanitizeUtf8(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($converted)) {
                return $converted;
            }
        }

        $cleaned = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return is_string($cleaned) ? $cleaned : $text;
    }

    /**
     * Pipe/Exec stdout is often LF-only; xterm needs CR+LF to start each line at column 0.
     */
    public static function normalizeTerminalNewlines(string $text): string
    {
        return preg_replace("/(?<!\r)\n/", "\r\n", $text) ?? $text;
    }
}
