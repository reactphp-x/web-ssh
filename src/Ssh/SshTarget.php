<?php

declare(strict_types=1);

namespace App\Ssh;

final class SshTarget
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly ?string $password = null,
        public readonly ?string $identityFile = null,
        public readonly ?string $privateKeyContent = null,
        public readonly ?self $jump = null,
    ) {
    }

    public function uri(): string
    {
        return $this->host . ':' . $this->port;
    }

    public function withJump(?self $jump): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->identityFile,
            $this->privateKeyContent,
            $jump,
        );
    }

    /**
     * @return list<string>
     */
    public function jumpLabels(): array
    {
        $labels = [];
        foreach (JumpHostChain::hopsFromTarget($this) as $hop) {
            $labels[] = sprintf('%s@%s:%d', $hop->user, $hop->host, $hop->port);
        }

        return $labels;
    }
}
