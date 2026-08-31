<?php

declare(strict_types=1);

namespace App\Neuron\Agent;

use App\Chat\ChatSettings;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;

trait ProvidesSummarizationMiddleware
{
    /**
     * @return array<class-string, list<Summarization>>
     */
    protected function summarizationMiddlewareEntries(ChatSettings $settings): array
    {
        if (!$settings->summarizationEnabled()) {
            return [];
        }

        $maxTokens = $settings->summarizationMaxTokens();
        if ($maxTokens <= 0) {
            return [];
        }

        $summarization = new Summarization(
            provider: $this->resolveProvider(),
            maxTokens: $maxTokens,
            messagesToKeep: $settings->summarizationMessagesToKeep(),
            summaryPrompt: $settings->summarizationPrompt(),
        );

        return [
            ChatNode::class => [$summarization],
            StreamingNode::class => [$summarization],
            StructuredOutputNode::class => [$summarization],
        ];
    }
}
