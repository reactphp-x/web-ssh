<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Agent\Middleware\RunSshCommandInputNormalizer;
use App\Neuron\Agent\Tools\OrchestratorRunSshCommandExecutorHandler;
use App\Neuron\Agent\Tools\OrchestratorRunSshCommandPendingHandler;
use App\Neuron\Tools\OrchestratorRunSshCommandTool;
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
            $this->prepareApprovedExecutors($node->getResumeRequest(), $event);

            return;
        }

        if ($node->isResuming()) {
            return;
        }

        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool->getName() !== OrchestratorRunSshCommandTool::NAME) {
                continue;
            }
            RunSshCommandInputNormalizer::apply(
                $tool,
                OrchestratorToolContext::commandTimeout(),
                OrchestratorToolContext::commandTimeoutMax(),
            );
            if (OrchestratorToolContext::commandApprovalRequired()) {
                $tool->setCallable(new OrchestratorRunSshCommandPendingHandler());
            } elseif ($tool instanceof OrchestratorRunSshCommandTool) {
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

            $callId = $tool->getCallId();
            $action = $callId === null ? null : $request->getAction($callId);
            if ($action !== null && $action->isApproved()) {
                $tool->setCallable(new OrchestratorRunSshCommandExecutorHandler($tool->getAiSessionId()));
            }
        }
    }
}
