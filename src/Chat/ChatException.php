<?php

declare(strict_types=1);

namespace App\Chat;

use RuntimeException;
use Throwable;

final class ChatException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly array $data = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
