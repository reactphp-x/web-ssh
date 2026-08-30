<?php

declare(strict_types=1);

namespace Tests\Chat;

use App\Chat\StreamChunkMapper;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

final class StreamChunkMapperTest extends TestCase
{
    public function testMapsToolCallWithInputs(): void
    {
        $tool = new class('demo_tool', 'demo') extends Tool {
            protected function properties(): array
            {
                return [
                    new ToolProperty('command', PropertyType::STRING, 'cmd', true),
                ];
            }
        };
        $tool->setCallId('call-1');
        $tool->setInputs(['command' => 'ls -la']);

        $mapped = (new StreamChunkMapper())->map(new ToolCallChunk($tool));

        self::assertSame('tool', $mapped['event']);
        self::assertSame('demo_tool', $mapped['data']['name']);
        self::assertSame('call', $mapped['data']['phase']);
        self::assertSame('call-1', $mapped['data']['callId']);
        self::assertSame(['command' => 'ls -la'], $mapped['data']['inputs']);
    }
}
