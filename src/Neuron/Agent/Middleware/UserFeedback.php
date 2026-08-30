<?php

declare(strict_types=1);

namespace App\Neuron\Agent\Middleware;

use App\Neuron\Agent\Tools\AskUserPendingHandler;
use App\Neuron\Agent\Tools\ToolFeedbackResultHandler;
use App\Neuron\Tools\AskUserTool;
use App\Neuron\Workflow\FeedbackField;
use App\Neuron\Workflow\FeedbackRequest;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function count;
use function is_array;
use function is_string;
use function json_encode;
use function sprintf;
use function trim;

final class UserFeedback implements WorkflowMiddleware
{
    /**
     * @param ToolNode $node
     * @param ToolCallEvent $event
     * @throws WorkflowInterrupt
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (! $event instanceof ToolCallEvent) {
            return;
        }

        if ($node->isResuming() && $node->getResumeRequest() instanceof FeedbackRequest) {
            $this->applyAnswers($node->getResumeRequest(), $event);

            return;
        }

        $tools = array_filter(
            $event->toolCallMessage->getTools(),
            static fn (ToolInterface $tool): bool => $tool->getName() === AskUserTool::NAME,
        );

        if ($tools === []) {
            return;
        }

        $tool = reset($tools);
        if (! $tool instanceof ToolInterface) {
            return;
        }

        $this->normalizeAskUserInputs($event);

        $inputs = $tool->getInputs();
        $message = isset($inputs['message']) && is_string($inputs['message'])
            ? trim($inputs['message'])
            : '请回答以下问题';
        $fields = $this->parseQuestions($inputs['questions'] ?? []);

        if ($fields === []) {
            $this->rejectInvalidAskUser($event);

            return;
        }

        foreach ($event->toolCallMessage->getTools() as $pendingTool) {
            if ($pendingTool->getName() !== AskUserTool::NAME) {
                continue;
            }
            $pendingTool->setCallable(new AskUserPendingHandler());
        }

        $count = count($fields);
        throw new WorkflowInterrupt(
            new FeedbackRequest(
                sprintf('%s（共 %d 题）', $message, $count),
                $fields,
            ),
            $node,
            $state,
            $event,
        );
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        //
    }

    /**
     * @return FeedbackField[]
     */
    private function parseQuestions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $fields = [];
        $index = 0;
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (
                (! isset($item['id']) || trim((string) $item['id']) === '')
                && isset($item['label']) && is_string($item['label'])
            ) {
                $item['id'] = 'q' . (++$index);
            }

            $field = FeedbackField::fromArray($item);
            if ($field instanceof FeedbackField) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function normalizeAskUserInputs(ToolCallEvent $event): void
    {
        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool->getName() !== AskUserTool::NAME || ! $tool instanceof Tool) {
                continue;
            }

            $inputs = $tool->getInputs();
            if (! isset($inputs['message']) || ! is_string($inputs['message']) || trim($inputs['message']) === '') {
                $inputs['message'] = '请回答以下问题';
            }
            if (! array_key_exists('questions', $inputs)) {
                $inputs['questions'] = [];
            }
            $tool->setInputs($inputs);
        }
    }

    private function rejectInvalidAskUser(ToolCallEvent $event): void
    {
        $error = 'ask_user 调用无效：questions 必须是非空数组，每题需包含 id、type、label 和 options（3-6 个选项）。';

        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool->getName() !== AskUserTool::NAME) {
                continue;
            }

            $tool->setCallable(new ToolFeedbackResultHandler($error));
        }
    }

    private function applyAnswers(FeedbackRequest $request, ToolCallEvent $event): void
    {
        $answers = [];
        foreach ($request->getFields() as $field) {
            $answers[$field->id] = $field->value;
        }

        $json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool->getName() !== AskUserTool::NAME) {
                continue;
            }

            $tool->setCallable(new ToolFeedbackResultHandler($json));
        }
    }
}
