<?php

declare(strict_types=1);

namespace App\Policy;

final readonly class CommandInvocation
{
    /**
     * @param list<string> $args
     */
    public function __construct(
        public string $binary,
        public array $args,
        public int $pipelineIndex,
    ) {
    }

    /**
     * @return list<string>
     */
    public function argv(): array
    {
        return array_merge([$this->binary], $this->args);
    }
}
