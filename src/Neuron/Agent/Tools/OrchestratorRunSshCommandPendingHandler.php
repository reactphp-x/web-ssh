<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Tools;

final class OrchestratorRunSshCommandPendingHandler
{
    /**
     * @return string JSON-encoded pending marker
     */
    public function __invoke(int $host_id, string $command, string $reason): string
    {
        return json_encode([
            'pending' => true,
            'host_id' => $host_id,
            'command' => $command,
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
