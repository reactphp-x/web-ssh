<?php

declare(strict_types=1);

namespace App\Ssh;

use Throwable;

final class SshErrorFormatter
{
    /**
     * @return array{message: string, detail: string, exception: string}
     */
    public static function format(Throwable $exception, SshTarget $target, bool $verbose = false): array
    {
        $message = self::summarize($exception, $target);
        $detail = self::buildDetail($exception, $target, $verbose);

        return [
            'message' => $message,
            'detail' => $detail,
            'exception' => $exception::class,
        ];
    }

    private static function summarize(Throwable $exception, SshTarget $target): string
    {
        $endpoint = sprintf('%s@%s:%d', $target->user, $target->host, $target->port);
        $jumps = $target->jumpLabels();
        if ($jumps !== []) {
            $endpoint .= ' via ' . implode(' -> ', $jumps);
        }
        $hint = self::hintFor($exception, $target);

        if ($hint !== null) {
            return sprintf('SSH connection to %s failed: %s', $endpoint, $hint);
        }

        return sprintf('SSH connection to %s failed: %s', $endpoint, $exception->getMessage());
    }

    private static function buildDetail(Throwable $exception, SshTarget $target, bool $verbose): string
    {
        $auth = $target->privateKeyContent !== null && $target->privateKeyContent !== ''
            ? 'private_key_pem'
            : ($target->identityFile !== null ? 'private_key_path' : ($target->password !== null ? 'password' : 'auto'));

        $lines = [
            sprintf('Target: %s@%s:%d', $target->user, $target->host, $target->port),
            sprintf('Auth: %s', $auth),
        ];
        $jumps = $target->jumpLabels();
        if ($jumps !== []) {
            $lines[] = 'Jump: ' . implode(' -> ', $jumps);
        }
        $lines[] = sprintf('Exception: %s', $exception::class);
        $lines[] = sprintf('Message: %s', $exception->getMessage());

        $hint = self::hintFor($exception, $target);
        if ($hint !== null) {
            $lines[] = 'Explanation: ' . $hint;
        }

        $previous = $exception->getPrevious();
        while ($previous !== null) {
            $lines[] = sprintf('Caused by: %s: %s', $previous::class, $previous->getMessage());
            $previous = $previous->getPrevious();
        }

        if ($verbose) {
            $lines[] = 'Stack trace:';
            $lines[] = $exception->getTraceAsString();
        }

        return implode("\n", $lines);
    }

    private static function hintFor(Throwable $exception, SshTarget $target): ?string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'public key authentication failed'),
            str_contains($message, 'password authentication failed'),
            str_contains($message, 'Permission denied') => 'SSH authentication rejected. Verify username, password, authorized_keys, and private key configuration.',
            str_contains($message, 'Private key is unreadable'),
            str_contains($message, 'SSH authentication requires'),
            str_contains($message, 'Cannot derive public key') => 'Check server-side private key path, permissions (chmod 600), and passphrase if the key is encrypted.',
            str_contains($message, 'Jump host tunnel failed'),
            str_contains($message, 'Jump host SSH process'),
            str_contains($message, 'Jump host tunnel did not'),
            $target->jump !== null && (
                str_contains($message, 'SSH probe to')
                || str_contains($message, 'SSH probe timed out')
            ) => 'Failed to reach the target through the jump host. Verify jump-host credentials, that `ssh` is installed, and that the target address is reachable from the jump host.',
            str_contains($message, 'SSH probe timed out'),
            str_contains($message, 'Connection timed out'),
            str_contains($message, 'timed out') => 'Connection timed out. Verify network routing, firewall rules, and that the host is online.',
            str_contains($message, 'Unable to connect'),
            str_contains($message, 'TCP connect to'),
            str_contains($message, 'Connection refused') => 'Connection refused. Verify the host is reachable and SSH is listening on the specified port.',
            default => null,
        };
    }
}
