<?php

declare(strict_types=1);

namespace App\Neuron\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

use function array_filter;
use function array_values;
use function is_array;
use function json_decode;
use function json_encode;

final class FeedbackRequest extends InterruptRequest
{
    /** @var array<string, FeedbackField> */
    private array $fields = [];

    /**
     * @param FeedbackField[] $fields
     */
    public function __construct(string $message, array $fields = [])
    {
        parent::__construct($message);

        foreach ($fields as $field) {
            $this->fields[$field->id] = $field;
        }
    }

    public function addField(FeedbackField $field): self
    {
        $this->fields[$field->id] = $field;

        return $this;
    }

    public function getField(string $id): ?FeedbackField
    {
        return $this->fields[$id] ?? null;
    }

    /**
     * @return FeedbackField[]
     */
    public function getFields(): array
    {
        return array_values($this->fields);
    }

    /**
     * @return FeedbackField[]
     */
    public function getPendingFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FeedbackField $field): bool => ! $field->isAnswered(),
        ));
    }

    public function setFieldValue(string $id, mixed $value): void
    {
        $field = $this->fields[$id] ?? null;
        if (! $field instanceof FeedbackField) {
            return;
        }

        $field->value = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'fields' => json_encode(array_values($this->fields)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self($data['message'] ?? '');

        if (! isset($data['fields'])) {
            return $instance;
        }

        $fieldsData = is_array($data['fields'])
            ? $data['fields']
            : json_decode((string) $data['fields'], true);

        if (! is_array($fieldsData)) {
            return $instance;
        }

        foreach ($fieldsData as $fieldData) {
            if (! is_array($fieldData)) {
                continue;
            }
            $field = FeedbackField::fromArray($fieldData);
            if ($field instanceof FeedbackField) {
                if (array_key_exists('value', $fieldData)) {
                    $field->value = $fieldData['value'];
                }
                $instance->addField($field);
            }
        }

        return $instance;
    }
}
