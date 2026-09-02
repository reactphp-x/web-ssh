<?php

declare(strict_types=1);

namespace App\Ssh;

use App\Recording\SessionRecorder;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Promise\reject;

final class SshSessionBridge
{
    /** @var array<string, array{
     *     username: string,
     *     session: SshTerminalSession,
     *     output: string,
     *     target: SshTarget,
     *     workspace: OpenSshWorkspace,
     *     session_id: int,
     *     host_id: int,
     *     host_group_id: ?int,
     * }> */
    private array $sessions = [];

    public function __construct(
        private readonly ?SshLiveRegistry $liveRegistry = null,
        private readonly ?SessionRecorder $recorder = null,
        private readonly ?\App\Policy\CommandPolicyEngine $policyEngine = null,
        private readonly ?\App\Repository\CommandExecutionRepository $commandExecutions = null,
        private readonly ?\App\Service\AuditService $audit = null,
    ) {
    }

    public function register(
        string $connId,
        string $username,
        SshTerminalSession $session,
        SshTarget $target,
        int $sessionId,
        int $hostId = 0,
        ?int $hostGroupId = null,
    ): void {
        $this->unregister($connId);

        $this->sessions[$connId] = [
            'username' => $username,
            'session' => $session,
            'output' => '',
            'target' => $target,
            'workspace' => OpenSshWorkspace::prepare($target, Ssh2Client::connectTimeout()),
            'session_id' => $sessionId,
            'host_id' => $hostId,
            'host_group_id' => $hostGroupId,
        ];
    }

    public function unregister(string $connId): void
    {
        $entry = $this->sessions[$connId] ?? null;
        if ($entry !== null) {
            $entry['workspace']->cleanup();
        }

        unset($this->sessions[$connId]);
    }

    public function appendOutput(string $connId, string $chunk): void
    {
        if (!isset($this->sessions[$connId])) {
            return;
        }

        $this->sessions[$connId]['output'] .= $chunk;
        $maxBytes = 65536;
        if (strlen($this->sessions[$connId]['output']) > $maxBytes) {
            $this->sessions[$connId]['output'] = substr($this->sessions[$connId]['output'], -$maxBytes);
        }
    }

    public function isConnected(string $connId): bool
    {
        return isset($this->sessions[$connId]);
    }

    public function isOwnedBy(string $connId, string $username): bool
    {
        return ($this->sessions[$connId]['username'] ?? '') === $username;
    }

    public function getRecentOutput(string $connId, int $maxChars = 4000): string
    {
        $output = $this->sessions[$connId]['output'] ?? '';
        $stripped = CommandOutputCollector::stripAnsi($output);
        if (strlen($stripped) <= $maxChars) {
            return trim($stripped);
        }

        return trim(substr($stripped, -$maxChars));
    }

    public function runCommand(string $connId, string $command, int $timeoutSec): PromiseInterface
    {
        $entry = $this->sessions[$connId] ?? null;
        if ($entry === null) {
            return reject(new RuntimeException('SSH 会话未连接或已断开。'));
        }

        $command = trim($command);
        if ($command === '') {
            return reject(new RuntimeException('命令不能为空。'));
        }

        $policyDecision = null;
        if ($this->policyEngine !== null) {
            $policyDecision = $this->policyEngine->evaluate($command, new \App\Policy\PolicyContext(
                username: $entry['username'],
                hostId: $entry['host_id'] > 0 ? $entry['host_id'] : null,
                hostGroupId: $entry['host_group_id'],
                source: 'terminal_ai',
                connId: $connId,
                threadKey: $connId,
            ));
            if ($policyDecision->action === \App\Policy\PolicyAction::Deny) {
                return reject(new RuntimeException($policyDecision->reason));
            }
        } elseif ($this->isBlockedCommand($command)) {
            return reject(new RuntimeException('该命令被禁止通过 AI 执行（交互式/TUI 命令）。'));
        }

        $sessionId = $entry['session_id'];
        $size = $this->liveRegistry?->getTerminalSize($connId) ?? ['cols' => 80, 'rows' => 24];
        $this->publishAiChunk($connId, $sessionId, self::formatLiveHeader($command));

        return SshExecRunner::run(
            $entry['workspace'],
            $command,
            $timeoutSec,
            $size['cols'],
            $size['rows'],
            function (string $chunk) use ($connId, $sessionId): void {
                $this->publishAiChunk($connId, $sessionId, $chunk);
            },
        )->then(function (CommandResult $result) use ($connId, $sessionId, $entry, $command, $policyDecision): CommandResult {
            $this->publishAiChunk(
                $connId,
                $sessionId,
                self::formatLiveFooter($result->exitCode, $result->timedOut),
            );
            $this->recordExecution($entry, $command, $result, $policyDecision);

            return $result;
        });
    }

    /**
     * @param array{username: string, session_id: int, host_id: int, host_group_id: ?int} $entry
     */
    private function recordExecution(array $entry, string $command, CommandResult $result, ?\App\Policy\PolicyDecision $policyDecision): void
    {
        if ($this->commandExecutions === null || $this->policyEngine === null) {
            return;
        }

        $decision = $policyDecision ?? $this->policyEngine->evaluate($command, new \App\Policy\PolicyContext(
            username: $entry['username'],
            hostId: $entry['host_id'] > 0 ? $entry['host_id'] : null,
            hostGroupId: $entry['host_group_id'],
            source: 'terminal_ai',
        ));

        $this->commandExecutions->write(
            $entry['username'],
            $entry['host_id'] > 0 ? $entry['host_id'] : null,
            $command,
            $decision->action->value,
            $decision->matchedRule,
            $decision->inspection->toAuditSummary(),
            $entry['session_id'],
            null,
            $result->exitCode,
            $result->timedOut,
        );
    }

    public function getHostContext(string $connId): ?array
    {
        $entry = $this->sessions[$connId] ?? null;
        if ($entry === null) {
            return null;
        }

        return [
            'host_id' => $entry['host_id'] > 0 ? $entry['host_id'] : null,
            'host_group_id' => $entry['host_group_id'],
            'username' => $entry['username'],
        ];
    }

    private function publishAiChunk(string $connId, int $sessionId, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $terminalChunk = CommandOutputCollector::normalizeTerminalNewlines($chunk);
        $this->liveRegistry?->writeOutput($connId, $terminalChunk);
        $this->recorder?->writeOutput($sessionId, $terminalChunk);
    }

    private static function formatLiveHeader(string $command): string
    {
        return "\r\n\x1b[33m[AI]\x1b[0m \x1b[32m$\x1b[0m " . $command . "\r\n";
    }

    private static function formatLiveFooter(?int $exitCode, bool $timedOut): string
    {
        if ($timedOut) {
            return "\x1b[33m[AI]\x1b[0m (timeout)\r\n";
        }

        return "\x1b[33m[AI]\x1b[0m exit " . ($exitCode ?? '?') . "\r\n";
    }

    private function isBlockedCommand(string $command): bool
    {
        // Temporarily disabled: substring blocklist false-positive on long scripts.
        // Re-enable via CommandBlocklist (first-command detection) when needed.
        return false;
    }
}
