<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Agent\Tools\OrchestratorRunSshCommandExecutorHandler;
use App\Neuron\Agent\Tools\OrchestratorRunSshCommandPendingHandler;
use App\Neuron\Agent\Tools\RunSshCommandExecutorHandler;
use App\Neuron\Agent\Tools\RunSshCommandPendingHandler;
use App\Neuron\Agent\Tools\ToolFeedbackResultHandler;
use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Neuron\Tools\RunSshCommandTool;
use App\Neuron\Tools\ToolJson;
use App\Policy\CommandPolicyEngine;
use App\Policy\PolicyAction;
use App\Policy\PolicyContext;
use App\Policy\PolicyDecision;
use App\Policy\PolicyDecisionStore;
use App\Ssh\OrchestratorToolContext;
use App\Ssh\SshToolContext;
use function React\Async\await;

final class RunSshCommandPolicyHelper
{
    public function __construct(
        private readonly CommandPolicyEngine $policyEngine,
    ) {
    }

    public function applyTerminalPolicy(RunSshCommandTool $tool): void
    {
        $command = trim((string) ($tool->getInputs()['command'] ?? ''));
        $decision = $this->policyEngine->evaluate($command, $this->terminalContext());
        PolicyDecisionStore::remember(SshToolContext::threadKey(), $command, $decision);
        $this->assignTerminalHandler($tool, $decision);
    }

    public function applyOrchestratorPolicy(OrchestratorRunSshCommandTool $tool): void
    {
        $inputs = $tool->getInputs();
        $command = trim((string) ($inputs['command'] ?? ''));
        $hostId = isset($inputs['host_id']) ? (int) $inputs['host_id'] : null;
        $decision = $this->policyEngine->evaluate(
            $command,
            $this->orchestratorContext(is_int($hostId) && $hostId > 0 ? $hostId : null),
        );
        PolicyDecisionStore::remember(OrchestratorToolContext::threadKey(), $command, $decision);
        $this->assignOrchestratorHandler($tool, $decision);
    }

    private function terminalContext(): PolicyContext
    {
        return new PolicyContext(
            username: SshToolContext::username(),
            hostId: SshToolContext::hostId(),
            hostGroupId: SshToolContext::hostGroupId(),
            source: 'terminal_ai',
            connId: SshToolContext::threadKey(),
            threadKey: SshToolContext::threadKey(),
        );
    }

    private function orchestratorContext(?int $hostId): PolicyContext
    {
        $resolvedHostId = $hostId;
        $hostGroupId = null;
        if ($resolvedHostId === null) {
            $segment = OrchestratorToolContext::execBridge()->getActiveSegment(
                OrchestratorToolContext::aiSessionId(),
            );
            $resolvedHostId = isset($segment['host_id']) ? (int) $segment['host_id'] : null;
        }

        if ($resolvedHostId !== null) {
            $host = await(OrchestratorToolContext::hosts()->findById($resolvedHostId));
            if (is_array($host) && isset($host['group_id']) && is_numeric($host['group_id'])) {
                $hostGroupId = (int) $host['group_id'];
            }
        }

        return new PolicyContext(
            username: OrchestratorToolContext::username(),
            hostId: $resolvedHostId,
            hostGroupId: $hostGroupId,
            source: 'orchestrator',
            aiSessionId: OrchestratorToolContext::aiSessionId(),
            threadKey: OrchestratorToolContext::threadKey(),
        );
    }

    private function assignTerminalHandler(RunSshCommandTool $tool, PolicyDecision $decision): void
    {
        match ($decision->action) {
            PolicyAction::Deny => $tool->setCallable(new ToolFeedbackResultHandler(ToolJson::encode([
                'ok' => false,
                'error' => $decision->reason,
                'policy' => $decision->toUiPayload(),
            ]))),
            PolicyAction::AutoRun => $tool->setCallable(new RunSshCommandExecutorHandler($tool->getConnId())),
            PolicyAction::RequireApproval => $this->assignTerminalApprovalHandler($tool, $decision),
        };
    }

    private function assignOrchestratorHandler(OrchestratorRunSshCommandTool $tool, PolicyDecision $decision): void
    {
        match ($decision->action) {
            PolicyAction::Deny => $tool->setCallable(new ToolFeedbackResultHandler(ToolJson::encode([
                'ok' => false,
                'error' => $decision->reason,
                'policy' => $decision->toUiPayload(),
            ]))),
            PolicyAction::AutoRun => $tool->setCallable(
                new OrchestratorRunSshCommandExecutorHandler($tool->getAiSessionId()),
            ),
            PolicyAction::RequireApproval => $this->assignOrchestratorApprovalHandler($tool, $decision),
        };
    }

    /**
     * Evaluate policy for the current tool inputs (used by ToolApproval middleware).
     */
    public static function terminalToolRequiresApproval(array $args): bool
    {
        return self::decisionRequiresApproval(
            self::evaluateTerminalDecision($args),
            SshToolContext::sessionTrustEnabled(),
        );
    }

    /**
     * Evaluate policy for the current tool inputs (used by ToolApproval middleware).
     */
    public static function orchestratorToolRequiresApproval(array $args): bool
    {
        return self::decisionRequiresApproval(
            self::evaluateOrchestratorDecision($args),
            OrchestratorToolContext::sessionTrustEnabled(),
        );
    }

    private static function decisionRequiresApproval(?PolicyDecision $decision, bool $sessionTrustEnabled): bool
    {
        if ($decision === null) {
            return true;
        }

        if ($decision->action === PolicyAction::AutoRun || $decision->action === PolicyAction::Deny) {
            return false;
        }

        return $decision->approvalRequiredWithTrust($sessionTrustEnabled);
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function evaluateTerminalDecision(array $args): ?PolicyDecision
    {
        $engine = SshToolContext::policyEngine();
        if ($engine === null) {
            return null;
        }

        $command = trim((string) ($args['command'] ?? $args['cmd'] ?? ''));
        $decision = $engine->evaluate($command, new PolicyContext(
            username: SshToolContext::username(),
            hostId: SshToolContext::hostId(),
            hostGroupId: SshToolContext::hostGroupId(),
            source: 'terminal_ai',
            connId: SshToolContext::threadKey(),
            threadKey: SshToolContext::threadKey(),
        ));
        PolicyDecisionStore::remember(SshToolContext::threadKey(), $command, $decision);

        return $decision;
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function evaluateOrchestratorDecision(array $args): ?PolicyDecision
    {
        $engine = OrchestratorToolContext::policyEngine();
        if ($engine === null) {
            return null;
        }

        $command = trim((string) ($args['command'] ?? $args['cmd'] ?? ''));
        $hostId = isset($args['host_id']) ? (int) $args['host_id'] : null;
        $decision = (new self($engine))->evaluateOrchestratorDecisionForHost(
            $command,
            is_int($hostId) && $hostId > 0 ? $hostId : null,
        );
        PolicyDecisionStore::remember(OrchestratorToolContext::threadKey(), $command, $decision);

        return $decision;
    }

    private function evaluateOrchestratorDecisionForHost(string $command, ?int $hostId): PolicyDecision
    {
        return $this->policyEngine->evaluate($command, $this->orchestratorContext($hostId));
    }

    private function assignTerminalApprovalHandler(RunSshCommandTool $tool, PolicyDecision $decision): void
    {
        if ($decision->approvalRequiredWithTrust(SshToolContext::sessionTrustEnabled())) {
            $tool->setCallable(new RunSshCommandPendingHandler());
        } else {
            $tool->setCallable(new RunSshCommandExecutorHandler($tool->getConnId()));
        }
    }

    private function assignOrchestratorApprovalHandler(
        OrchestratorRunSshCommandTool $tool,
        PolicyDecision $decision,
    ): void {
        if ($decision->approvalRequiredWithTrust(OrchestratorToolContext::sessionTrustEnabled())) {
            $tool->setCallable(new OrchestratorRunSshCommandPendingHandler());
        } else {
            $tool->setCallable(new OrchestratorRunSshCommandExecutorHandler($tool->getAiSessionId()));
        }
    }

    public static function enrichApprovalAction(string $threadKey, string $command, array $action): array
    {
        $decision = PolicyDecisionStore::get($threadKey, $command);
        if ($decision === null) {
            return $action;
        }

        $action['policy'] = $decision->toUiPayload();

        return $action;
    }

    public static function extractCommandFromDescription(string $description): string
    {
        $decoded = json_decode($description, true);
        if (!is_array($decoded)) {
            return '';
        }

        $command = $decoded['command'] ?? $decoded['cmd'] ?? '';

        return is_string($command) ? trim($command) : '';
    }
}
