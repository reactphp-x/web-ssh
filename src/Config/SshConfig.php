<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;
use ReactphpX\Framework\Environment;

final class SshConfig
{
    /** @var list<string> */
    private readonly array $identityCandidates;

    private function __construct(
        private readonly ?string $defaultIdentity,
        array $identityCandidates,
        private readonly float $connectTimeout,
    ) {
        $this->identityCandidates = array_values(array_filter(array_map(
            static fn (string $path): ?string => self::expandPath($path),
            $identityCandidates,
        )));
    }

    public static function load(Environment $env): self
    {
        /** @var array{default_identity?: string|null, identity_candidates?: list<string>, connect_timeout?: float|int|string} $config */
        $config = require $env->basePath() . '/config/ssh.php';

        $defaultIdentity = $config['default_identity'] ?? null;
        if ($defaultIdentity === null || $defaultIdentity === '') {
            $defaultIdentity = $env->nullableString('SSH_IDENTITY_FILE');
        }

        if ($defaultIdentity !== null && $defaultIdentity !== '') {
            $defaultIdentity = self::expandPath($defaultIdentity);
        } else {
            $defaultIdentity = null;
        }

        $connectTimeout = $env->nullableString('SSH_CONNECT_TIMEOUT')
            ?? (string) ($config['connect_timeout'] ?? 10);

        return new self(
            $defaultIdentity,
            $config['identity_candidates'] ?? ['~/.ssh/id_ed25519', '~/.ssh/id_rsa'],
            max(1.0, (float) $connectTimeout),
        );
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function defaultIdentity(): ?string
    {
        return $this->defaultIdentity;
    }

    public function resolveIdentity(?string $identityFile): ?string
    {
        if ($identityFile !== null && $identityFile !== '') {
            $path = self::expandPath($identityFile);

            return is_readable($path) ? $path : null;
        }

        if ($this->defaultIdentity !== null && is_readable($this->defaultIdentity)) {
            return $this->defaultIdentity;
        }

        foreach ($this->identityCandidates as $candidate) {
            if ($candidate !== null && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function suggestedKeyPaths(): array
    {
        $paths = [];
        if ($this->defaultIdentity !== null && $this->defaultIdentity !== '') {
            $paths[] = $this->defaultIdentity;
        }

        foreach ($this->identityCandidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && !in_array($candidate, $paths, true)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    public static function expandPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if ($path[0] === '~') {
            $home = getenv('HOME') ?: '';
            if ($home === '') {
                throw new InvalidArgumentException('Cannot expand ~ path without HOME.');
            }

            return $home . substr($path, 1);
        }

        return $path;
    }
}
