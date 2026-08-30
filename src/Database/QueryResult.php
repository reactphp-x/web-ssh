<?php

declare(strict_types=1);

namespace App\Database;

final class QueryResult
{
    /**
     * @param list<array<string, mixed>> $resultRows
     */
    public function __construct(
        public readonly array $resultRows,
        public readonly int $insertId,
        public readonly int $affectedRows,
    ) {
    }
}
