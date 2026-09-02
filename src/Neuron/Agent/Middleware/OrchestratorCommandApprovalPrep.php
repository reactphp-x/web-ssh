<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Agent\Tools\OrchestratorRunSshCommandExecutorHandler;
use App\Neuron\Agent\Tools\OrchestratorRunSshCommandPendingHandler;
use App\Neuron\Agent\Tools\ToolFeedbackResultHandler;
use App\Neuron\Tools\GetCommandContextTool;
use App\Neuron\Tools\ListHostsTool;
use App\Neuron\Tools\OrchestratorRunSshCommandTool;
use App\Neuron\Tools\ToolJson;
use App\Ssh\OrchestratorToolContext;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

final class OrchestratorCommandApprovalPrep implements WorkflowMiddleware
{
    /**
     * @param ToolNode $node
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        if ($node->isResuming() && $node->getResumeRequest() instanceof ApprovalRequest) {
            $this->normalizeOrchestratorRunSshCommands($event);
            $this->prepareApprovedExecutors($node->getResumeRequest(), $event);

            return;
        }

        if ($node->isResuming()) {
            return;
        }

        $this->normalizeOrchestratorRunSshCommands($event);
    }

    private function normalizeOrchestratorRunSshCommands(ToolCallEvent $event): void
    {
        OrchestratorToolContext::useSession($this->resolveOrchestratorSessionId($event));

        $policyEngine = OrchestratorToolContext::policyEngine();
        $policyHelper = $policyEngine !== null ? new RunSshCommandPolicyHelper($policyEngine) : null;

        $activeHostId = OrchestratorToolContext::execBridge()->getActiveSegment(
            OrchestratorToolContext::aiSessionId(),
        )['host_id'] ?? null;

        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool->getName() !== OrchestratorRunSshCommandTool::NAME) {
                continue;
            }

            if (!$tool instanceof OrchestratorRunSshCommandTool) {
                continue;
            }

            OrchestratorToolContext::useSession($tool->getAiSessionId());

            $error = OrchestratorRunSshCommandInputNormalizer::apply(
                $tool,
                OrchestratorToolContext::commandTimeout(),
                OrchestratorToolContext::commandTimeoutMax(),
                is_int($activeHostId) ? $activeHostId : null,
            );
            if ($error !== null) {
                $tool->setCallable(new ToolFeedbackResultHandler(ToolJson::encode([
                    'ok' => false,
                    'error' => $error,
                ])));

                continue;
            }

            if ($policyHelper !== null && $tool instanceof OrchestratorRunSshCommandTool) {
                $policyHelper->applyOrchestratorPolicy($tool);
                continue;
            }

            if (!$tool instanceof OrchestratorRunSshCommandTool) {
                continue;
            }

            if (RunSshCommandPolicyHelper::orchestratorToolRequiresApproval($tool->getInputs())) {
                $tool->setCallable(new OrchestratorRunSshCommandPendingHandler());
            } else {
                $tool->setCallable(new OrchestratorRunSshCommandExecutorHandler($tool->getAiSessionId()));
            }
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }

    private function prepareApprovedExecutors(ApprovalRequest $request, ToolCallEvent $event): void
    {
        foreach ($event->toolCallMessage->getTools() as $tool) {
            if (!$tool instanceof OrchestratorRunSshCommandTool) {
                continue;
            }

            OrchestratorToolContext::useSession($tool->getAiSessionId());

            $callId = $tool->getCallId();
            $action = $callId === null ? null : $request->getAction($callId);
            if ($action !== null && $action->isApproved()) {
                $tool->setCallable(new OrchestratorRunSshCommandExecutorHandler($tool->getAiSessionId()));
            }
        }
    }

    private function resolveOrchestratorSessionId(ToolCallEvent $event): int
    {
        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool instanceof OrchestratorRunSshCommandTool) {
                return $tool->getAiSessionId();
            }
            if ($tool instanceof ListHostsTool) {
                return $tool->getAiSessionId();
            }
            if ($tool instanceof GetCommandContextTool) {
                return $tool->getAiSessionId();
            }
        }

        return OrchestratorToolContext::aiSessionId();
    }
}
