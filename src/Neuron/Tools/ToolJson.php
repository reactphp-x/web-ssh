<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

final class ToolJson
{
    /**
     * Neuron Tool::setResult() json_encodes arrays; invalid UTF-8 makes that return false and crash.
     *
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            return $json;
        }

        return json_encode([
            'ok' => false,
            'error' => 'Failed to encode tool result: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"error":"Failed to encode tool result"}';
    }
}
