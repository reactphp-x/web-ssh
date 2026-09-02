<?php

declare(strict_types=1);

namespace App\Tests\Neuron\Agent\Middleware;

use App\Neuron\Agent\Middleware\OrchestratorRunSshCommandInputNormalizer;
use App\Neuron\Agent\Middleware\RunSshCommandInputNormalizer;
use App\Neuron\Agent\Tools\ToolFeedbackResultHandler;
use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Neuron\Tools\RunSshCommandTool;
use PHPUnit\Framework\TestCase;

final class OrchestratorRunSshCommandInputNormalizerTest extends TestCase
{
    public function testInjectsActiveHostIdWhenMissing(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'command' => 'ls',
            'reason' => 'list files',
        ]);

        $error = OrchestratorRunSshCommandInputNormalizer::apply($tool, 30, 300, 42);

        self::assertNull($error);
        self::assertSame(42, $tool->getInputs()['host_id']);
        self::assertSame(30, $tool->getInputs()['timeout_sec']);
    }

    public function testKeepsExplicitHostId(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'host_id' => 7,
            'command' => 'ls',
            'reason' => 'list files',
            'timeout_sec' => 120,
        ]);

        $error = OrchestratorRunSshCommandInputNormalizer::apply($tool, 30, 300, 42);

        self::assertNull($error);
        self::assertSame(7, $tool->getInputs()['host_id']);
        self::assertSame(120, $tool->getInputs()['timeout_sec']);
    }

    public function testReturnsErrorWhenHostIdUnavailable(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'command' => 'ls',
            'reason' => 'list files',
        ]);

        $error = OrchestratorRunSshCommandInputNormalizer::apply($tool, 30, 300, null);

        self::assertSame(
            '缺少 host_id：请先调用 list_hosts 选择目标主机，或在 run_ssh_command 中传入 host_id。',
            $error,
        );
        self::assertSame(0, $tool->getInputs()['host_id']);
    }

    public function testReturnsErrorWhenCommandMissing(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'host_id' => 3,
            'reason' => 'check php modules',
        ]);

        $error = OrchestratorRunSshCommandInputNormalizer::apply($tool, 30, 300, null);

        self::assertSame('缺少 command：run_ssh_command 必须包含要执行的 shell 命令。', $error);
        self::assertSame('', $tool->getInputs()['command']);
        self::assertSame(3, $tool->getInputs()['host_id']);
    }

    public function testNormalizesCmdAlias(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'host_id' => 2,
            'cmd' => 'php -v',
            'reason' => 'check php',
        ]);

        $error = OrchestratorRunSshCommandInputNormalizer::apply($tool, 30, 300, null);

        self::assertNull($error);
        self::assertSame('php -v', $tool->getInputs()['command']);
    }

    public function testDefaultsMissingReason(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'host_id' => 2,
            'command' => 'php -v',
        ]);

        $error = OrchestratorRunSshCommandInputNormalizer::apply($tool, 30, 300, null);

        self::assertNull($error);
        self::assertSame('未说明', $tool->getInputs()['reason']);
    }

    public function testExecuteDoesNotThrowWhenRequiredPlaceholdersAndErrorHandlerSet(): void
    {
        $tool = new OrchestratorRunSshCommandTool(1);
        $tool->setInputs([
            'host_id' => 0,
            'command' => '',
            'reason' => 'list files',
            'timeout_sec' => 30,
        ]);
        $tool->setCallable(new ToolFeedbackResultHandler('{"ok":false,"error":"missing host_id"}'));

        $tool->execute();

        self::assertSame('{"ok":false,"error":"missing host_id"}', $tool->getResult());
    }

    public function testResolveHostIdPrefersExplicitValue(): void
    {
        self::assertSame(9, OrchestratorRunSshCommandInputNormalizer::resolveHostId(['host_id' => 9], 42));
    }

    public function testResolveHostIdFallsBackToActiveHost(): void
    {
        self::assertSame(42, OrchestratorRunSshCommandInputNormalizer::resolveHostId([], 42));
    }

    public function testResolveHostIdIgnoresInvalidValues(): void
    {
        self::assertSame(42, OrchestratorRunSshCommandInputNormalizer::resolveHostId(['host_id' => 0], 42));
        self::assertSame(42, OrchestratorRunSshCommandInputNormalizer::resolveHostId(['host_id' => ''], 42));
        self::assertNull(OrchestratorRunSshCommandInputNormalizer::resolveHostId(['host_id' => 0], null));
    }

    public function testResolveHostIdAcceptsStringNumericValue(): void
    {
        self::assertSame(5, OrchestratorRunSshCommandInputNormalizer::resolveHostId(['host_id' => '5'], null));
    }

    public function testTerminalNormalizerReturnsErrorWhenCommandMissing(): void
    {
        $tool = new RunSshCommandTool('conn-1');
        $tool->setInputs([
            'reason' => 'check disk',
        ]);

        $error = RunSshCommandInputNormalizer::applyTerminal($tool, 30, 300);

        self::assertSame('缺少 command：run_ssh_command 必须包含要执行的 shell 命令。', $error);
        self::assertSame('', $tool->getInputs()['command']);
    }
}
