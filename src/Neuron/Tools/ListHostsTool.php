<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Ssh\OrchestratorToolContext;
use NeuronAI\Tools\Tool;

use function React\Async\await;

final class ListHostsTool extends Tool
{
    public const NAME = 'list_hosts';

    public function __construct()
    {
        parent::__construct(
            self::NAME,
            '列出平台已保存的主机，用于选择目标服务器。只读，无需用户批准。',
        );
    }

    protected function properties(): array
    {
        return [];
    }

    /**
     * @return string JSON-encoded tool result
     */
    public function __invoke(): string
    {
        $hosts = await(OrchestratorToolContext::hosts()->listOptions());

        return ToolJson::encode([
            'ok' => true,
            'hosts' => $hosts,
            'count' => count($hosts),
        ]);
    }
}
