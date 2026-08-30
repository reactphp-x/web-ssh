<?php

declare(strict_types=1);

namespace App\Ssh;

final class CommandResult
{
    public function __construct(
        public readonly string $output,
        public readonly ?int $exitCode,
        public readonly bool $timedOut,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'exit_code' => $this->exitCode,
            'timed_out' => $this->timedOut,
        ];
    }
}
