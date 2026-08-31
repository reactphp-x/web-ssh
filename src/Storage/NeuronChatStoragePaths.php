<?php

declare(strict_types=1);

namespace App\Storage;

final class NeuronChatStoragePaths
{
    public function __construct(
        private readonly string $neuronBase,
    ) {
    }

    public function connChatDirectory(string $connId): string
    {
        $legacyFile = rtrim($this->neuronBase, '/') . '/neuron_' . $connId . '.chat';
        if (is_file($legacyFile)) {
            return rtrim($this->neuronBase, '/');
        }

        return rtrim($this->neuronBase, '/') . '/' . DatedStorageLayout::datePath();
    }
}
