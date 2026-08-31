<?php

declare(strict_types=1);

namespace App\Neuron\Agent;

use App\Neuron\AiProviderFactory;
use NeuronAI\Providers\AIProviderInterface;

trait ResolvesConfiguredProvider
{
    protected function resolveConfiguredProvider(): AIProviderInterface
    {
        return AiProviderFactory::create(
            $this->requireSettings(),
            $this->http ?? null,
        );
    }

    abstract private function requireSettings(): \App\Chat\ChatSettings;
}
