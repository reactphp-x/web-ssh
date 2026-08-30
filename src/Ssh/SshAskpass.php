<?php

declare(strict_types=1);

namespace App\Ssh;

final class SshAskpass
{
    /**
     * @param array{passwords?: array<string, string>, passphrases?: array<string, string>, default?: string} $map
     */
    public static function secretForPrompt(array $map, string $prompt): string
    {
        $passwords = $map['passwords'] ?? [];
        $passphrases = $map['passphrases'] ?? [];

        if (preg_match('/passphrase for key [\'"](.+?)[\'"]/i', $prompt, $matches) === 1) {
            return (string) ($passphrases[$matches[1]] ?? $map['default'] ?? '');
        }

        if (preg_match('/^(.+?)@(.+?)\'s password:/i', $prompt, $matches) === 1) {
            $userAtHost = $matches[1] . '@' . $matches[2];

            return (string) ($passwords[$userAtHost] ?? $passwords[$matches[2]] ?? $map['default'] ?? '');
        }

        if (preg_match('/password for (.+?)@(.+?):\s*$/i', $prompt, $matches) === 1) {
            $userAtHost = $matches[1] . '@' . $matches[2];

            return (string) ($passwords[$userAtHost] ?? $passwords[$matches[2]] ?? $map['default'] ?? '');
        }

        return (string) ($map['default'] ?? '');
    }
}
