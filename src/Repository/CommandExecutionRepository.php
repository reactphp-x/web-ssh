<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\DatabaseClient;
use function React\Async\await;

final class CommandExecutionRepository
{
    public function __construct(private readonly DatabaseClient $db)
    {
    }

    /**
     * @param array<string, mixed> $inspectionSummary
     */
    public function write(
        string $username,
        ?int $hostId,
        string $command,
        string $decision,
        ?string $matchedRule,
        array $inspectionSummary,
        ?int $sessionId,
        ?int $aiSessionId,
        ?int $exitCode,
        bool $timedOut,
    ): void {
        await($this->db->query(
            'INSERT INTO command_executions (
                username, host_id, command, decision, matched_rule, inspection_json,
                session_id, ai_session_id, exit_code, timed_out
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $username,
                $hostId,
                $command,
                $decision,
                $matchedRule,
                json_encode($inspectionSummary, JSON_THROW_ON_ERROR),
                $sessionId,
                $aiSessionId,
                $exitCode,
                $timedOut ? 1 : 0,
            ],
        ));
    }
}
