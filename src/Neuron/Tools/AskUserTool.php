<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Neuron\Agent\Tools\AskUserPendingHandler;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * 向用户收集反馈（优先 radio / checkbox）。执行由 {@see UserFeedback} middleware 中断并 resume。
 */
final class AskUserTool extends Tool
{
    public const NAME = 'ask_user';

    public function __construct()
    {
        parent::__construct(
            self::NAME,
            'Ask clarifying questions when the user request is vague. Prefer clickable choices over free text. '
            . 'Use radio for single choice and checkbox for multiple choice. Avoid type=text unless absolutely necessary. '
            . 'Each question needs id, type, label, and options [{value, label}] with 3-6 concrete choices. '
            . 'The UI labels options A, B, C, D…; users may type letter combos like AB in the other text field. '
            . 'Always include an "other" option (value: other, label: 其他) for edge cases; the UI shows a text box when other is selected. '
            . 'Set required=true only when an answer is mandatory; otherwise users may skip or submit partial answers.',
            [
                ToolProperty::make(
                    'message',
                    PropertyType::STRING,
                    'Context message shown above the questions (optional; UI shows a default if omitted)',
                    false,
                ),
                ArrayProperty::make(
                    'questions',
                    'Questions to ask the user, all shown together',
                    true,
                    ObjectProperty::make(
                        'question',
                        'A single question',
                        true,
                        null,
                        [
                            ToolProperty::make('id', PropertyType::STRING, 'Unique question id', true),
                            ToolProperty::make(
                                'type',
                                PropertyType::STRING,
                                'Question type: prefer radio or checkbox; avoid text',
                                true,
                                ['radio', 'checkbox', 'select'],
                            ),
                            ToolProperty::make('label', PropertyType::STRING, 'Question label', true),
                            ArrayProperty::make(
                                'options',
                                'Choices for radio/checkbox (required, 3-6 items plus other when useful)',
                                true,
                                ObjectProperty::make(
                                    'option',
                                    'A choice option',
                                    true,
                                    null,
                                    [
                                        ToolProperty::make('value', PropertyType::STRING, 'Option value', true),
                                        ToolProperty::make('label', PropertyType::STRING, 'Option label', true),
                                    ],
                                ),
                                2,
                            ),
                            ToolProperty::make(
                                'required',
                                PropertyType::BOOLEAN,
                                'Whether an answer is required (default true; false means skippable)',
                                false,
                            ),
                        ],
                    ),
                    1,
                ),
            ],
        );

        $this->setCallable(new AskUserPendingHandler());
    }
}
