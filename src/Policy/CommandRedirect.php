<?php

declare(strict_types=1);

namespace App\Policy;

final readonly class CommandRedirect
{
    public function __construct(
        public string $operator,
        public string $target,
    ) {
    }
}
