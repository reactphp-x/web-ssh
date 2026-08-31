<?php

declare(strict_types=1);

namespace App\Chat;

use App\Ssh\CommandOutputCollector;

final class ChatUtf8
{
    public static function sanitize(string $text): string
    {
        return CommandOutputCollector::sanitizeUtf8($text);
    }

    public static function toolResult(mixed $result): mixed
    {
        if (!is_string($result)) {
            return $result;
        }

        $result = self::sanitize($result);
        if (strlen($result) > 4000) {
            return mb_strcut($result, 0, 4000, 'UTF-8') . '…';
        }

        return $result;
    }
}
