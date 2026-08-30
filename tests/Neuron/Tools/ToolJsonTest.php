<?php

declare(strict_types=1);

namespace App\Tests\Neuron\Tools;

use App\Neuron\Tools\ToolJson;
use PHPUnit\Framework\TestCase;

final class ToolJsonTest extends TestCase
{
    public function testEncodeHandlesInvalidUtf8(): void
    {
        $json = ToolJson::encode([
            'ok' => true,
            'context' => "hello \xC0\x28 world",
        ]);

        self::assertIsString($json);
        self::assertStringContainsString('"ok":true', $json);
        self::assertStringNotContainsString("\xC0", $json);
    }
}
