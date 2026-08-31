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
    private bool $summarizationMiddlewareRegistered = false;

    protected function registerSummarizationMiddleware(ChatSettings $settings): void
    {
        if ($this->summarizationMiddlewareRegistered || !$settings->summarizationEnabled()) {
            return;
        }

        $maxTokens = $settings->summarizationMaxTokens();
        if ($maxTokens <= 0) {
            return;
        }

        $summarization = new Summarization(
            provider: $this->resolveProvider(),
            maxTokens: $maxTokens,
            messagesToKeep: $settings->summarizationMessagesToKeep(),
            summaryPrompt: $settings->summarizationPrompt(),
        );

        foreach ([ChatNode::class, StreamingNode::class, StructuredOutputNode::class] as $nodeClass) {
            $this->addMiddleware($nodeClass, $summarization);
        }

        $this->summarizationMiddlewareRegistered = true;
    }
}
