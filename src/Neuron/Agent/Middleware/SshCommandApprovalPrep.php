<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Agent\Middleware\RunSshCommandInputNormalizer;
use App\Neuron\Agent\Tools\RunSshCommandExecutorHandler;
use App\Neuron\Agent\Tools\RunSshCommandPendingHandler;
use App\Neuron\Tools\RunSshCommandTool;
use App\Ssh\SshToolContext;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

final class SshCommandApprovalPrep implements WorkflowMiddleware
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
            if ($tool->getName() !== RunSshCommandTool::NAME) {
                continue;
            }
            RunSshCommandInputNormalizer::apply(
                $tool,
                SshToolContext::commandTimeout(),
                SshToolContext::commandTimeoutMax(),
            );
            if (SshToolContext::commandApprovalRequired()) {
                $tool->setCallable(new RunSshCommandPendingHandler());
            } elseif ($tool instanceof RunSshCommandTool) {
                $tool->setCallable(new RunSshCommandExecutorHandler($tool->getConnId()));
            }
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }

    private function prepareApprovedExecutors(ApprovalRequest $request, ToolCallEvent $event): void
    {
        foreach ($event->toolCallMessage->getTools() as $tool) {
            if (!$tool instanceof RunSshCommandTool) {
                continue;
            }

            $callId = $tool->getCallId();
            $action = $callId === null ? null : $request->getAction($callId);
            if ($action !== null && $action->isApproved()) {
                $tool->setCallable(new RunSshCommandExecutorHandler($tool->getConnId()));
            }
        }
    }
}
