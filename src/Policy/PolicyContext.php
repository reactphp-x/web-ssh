<?php

declare(strict_types=1);

namespace App\Policy;

final readonly class PolicyContext
{
    public function __construct(
        public string $username,
        public ?int $hostId,
        public ?int $hostGroupId,
        public string $source,
        public ?int $aiSessionId = null,
        public string $connId = '',
        public string $threadKey = '',
    ) {
    }
}
