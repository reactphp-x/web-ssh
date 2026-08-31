<?php

declare(strict_types=1);

namespace App\Ssh;

use RuntimeException;

final class OpenSshWorkspace
{
    public const TARGET_ALIAS = 'target';

    private function __construct(
        public readonly string $directory,
        public readonly string $configPath,
        public readonly string $askpassMapPath,
        public readonly string $askpassPath,
    ) {
    }

    public static function prepare(SshTarget $target, float $connectTimeout): self
    {
        $directory = sys_get_temp_dir() . '/webssh-openssh-' . uniqid('', true);
        if (!@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create OpenSSH workspace.');
        }

        $askpassSrc = dirname(__DIR__, 2) . '/bin/ssh-askpass.php';
        if (!is_readable($askpassSrc)) {
            throw new RuntimeException('Failed to prepare SSH askpass helper.');
        }

        $workspace = new self(
            $directory,
            $directory . '/ssh_config',
            $directory . '/askpass.json',
            $askpassSrc,
        );

        try {
            $hops = JumpHostChain::hopsFromTarget($target);
            $preparedHops = [];
            foreach ($hops as $index => $hop) {
                $preparedHops[] = [
                    'alias' => 'hop-' . $index,
                    'target' => $hop,
                    'identityFile' => self::materializeIdentity($hop, $directory, 'hop-' . $index),
                ];
            }

            $targetIdentity = self::materializeIdentity($target, $directory, 'target');
            file_put_contents(
                $workspace->configPath,
                SshJumpConfig::renderSession($preparedHops, $target, $targetIdentity, $connectTimeout),
            );
            chmod($workspace->configPath, 0600);

            file_put_contents(
                $workspace->askpassMapPath,
                json_encode(self::askpassMap($preparedHops, $target, $targetIdentity), JSON_UNESCAPED_SLASHES),
            );
            chmod($workspace->askpassMapPath, 0600);
        } catch (\Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }

        return $workspace;
    }

    public function cleanup(): void
    {
        self::removeDir($this->directory);
    }

    /**
     * @param list<array{alias: string, target: SshTarget, identityFile: ?string}> $hops
     *
     * @return array{passwords: array<string, string>, passphrases: array<string, string>}
     */
    private static function askpassMap(array $hops, SshTarget $target, ?string $targetIdentity): array
    {
        $passwords = [];
        $passphrases = [];

        foreach ($hops as $hop) {
            self::collectSecrets($hop['target'], $hop['identityFile'], $passwords, $passphrases);
        }

        self::collectSecrets($target, $targetIdentity, $passwords, $passphrases);

        return [
            'passwords' => $passwords,
            'passphrases' => $passphrases,
        ];
    }

    /**
     * @param array<string, string> $passwords
     * @param array<string, string> $passphrases
     */
    private static function collectSecrets(
        SshTarget $target,
        ?string $identityFile,
        array &$passwords,
        array &$passphrases,
    ): void {
        $secret = (string) ($target->password ?? '');
        if ($secret === '') {
            return;
        }

        if ($identityFile !== null && $identityFile !== '') {
            $passphrases[$identityFile] = $secret;

            return;
        }

        $passwords[$target->user . '@' . $target->host] = $secret;
        $passwords[$target->host] = $secret;
    }

    private static function materializeIdentity(SshTarget $target, string $workDir, string $name): ?string
    {
        if ($target->privateKeyContent !== null && $target->privateKeyContent !== '') {
            $path = $workDir . '/id-' . $name;
            file_put_contents($path, $target->privateKeyContent);
            chmod($path, 0600);

            return $path;
        }

        if ($target->identityFile !== null && $target->identityFile !== '') {
            if (!is_readable($target->identityFile)) {
                throw new RuntimeException(sprintf(
                    'Private key is not readable: %s',
                    $target->identityFile,
                ));
            }

            return $target->identityFile;
        }

        return null;
    }

    private static function removeDir(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = @scandir($directory);
        if (!is_array($items)) {
            @rmdir($directory);

            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                self::removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
