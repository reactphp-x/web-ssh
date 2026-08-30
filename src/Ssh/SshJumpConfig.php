<?php

declare(strict_types=1);

namespace App\Ssh;

final class SshJumpConfig
{
    /**
     * @param list<array{alias: string, target: SshTarget, identityFile: ?string}> $hops
     */
    public static function renderSession(
        array $hops,
        SshTarget $target,
        ?string $targetIdentityFile,
        float $connectTimeout,
    ): string {
        $config = self::render($hops, $connectTimeout);
        $config .= 'Host ' . OpenSshWorkspace::TARGET_ALIAS . "\n";
        $config .= self::line('HostName', $target->host);
        $config .= self::line('Port', (string) $target->port);
        $config .= self::line('User', $target->user);

        if ($hops !== []) {
            $config .= self::line('ProxyJump', $hops[array_key_last($hops)]['alias']);
        }

        $config .= self::authBlock($targetIdentityFile);
        $config .= "\n";

        return $config;
    }

    /**
     * @param list<array{alias: string, target: SshTarget, identityFile: ?string}> $hops
     */
    public static function render(array $hops, float $connectTimeout): string
    {
        $timeout = max(1, (int) ceil($connectTimeout));
        $out = "Host *\n";
        $out .= "  StrictHostKeyChecking no\n";
        $out .= "  UserKnownHostsFile /dev/null\n";
        $out .= "  GlobalKnownHostsFile /dev/null\n";
        $out .= "  LogLevel ERROR\n";
        $out .= "  ConnectTimeout {$timeout}\n";
        $out .= "  IdentitiesOnly yes\n";
        $out .= "  NumberOfPasswordPrompts 1\n";
        $out .= "\n";

        foreach ($hops as $index => $hop) {
            $entry = $hop['target'];
            $out .= 'Host ' . $hop['alias'] . "\n";
            $out .= self::line('HostName', $entry->host);
            $out .= self::line('Port', (string) $entry->port);
            $out .= self::line('User', $entry->user);
            if ($index > 0) {
                $out .= self::line('ProxyJump', $hops[$index - 1]['alias']);
            }

            $out .= self::authBlock($hop['identityFile']);
            $out .= "\n";
        }

        return $out;
    }

    private static function authBlock(?string $identityFile): string
    {
        if ($identityFile !== null && $identityFile !== '') {
            return self::line('IdentityFile', $identityFile)
                . self::line('PreferredAuthentications', 'publickey,keyboard-interactive,password');
        }

        return self::line('IdentityFile', '/dev/null')
            . self::line('PubkeyAuthentication', 'no')
            . self::line('PreferredAuthentications', 'keyboard-interactive,password');
    }

    private static function line(string $key, string $value): string
    {
        if (preg_match('/[\s"]/', $value) === 1) {
            $value = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return '  ' . $key . ' ' . $value . "\n";
    }
}
