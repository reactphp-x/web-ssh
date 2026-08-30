<?php

declare(strict_types=1);

namespace App\Neuron\Workflow;

use JsonSerializable;

use function in_array;
use function is_array;
use function is_string;
use function trim;

/**
 * 单条用户反馈表单项（radio / checkbox / text / select）。
 */
final class FeedbackField implements JsonSerializable
{
    /**
     * @param list<array{value: string, label: string}>|null $options
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly ?array $options = null,
        public readonly bool $required = true,
        public readonly ?string $placeholder = null,
        public mixed $value = null,
    ) {
    }

    public function isAnswered(): bool
    {
        if ($this->value === null) {
            return false;
        }

        if ($this->type === 'checkbox') {
            return is_array($this->value) && $this->value !== [];
        }

        if (is_string($this->value)) {
            return trim($this->value) !== '';
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = isset($data['id']) && is_string($data['id']) ? trim($data['id']) : '';
        $type = isset($data['type']) && is_string($data['type']) ? trim(strtolower($data['type'])) : '';
        $label = isset($data['label']) && is_string($data['label']) ? trim($data['label']) : '';

        if ($id === '' || $type === '' || $label === '') {
            return null;
        }

        if ($type === 'select') {
            $type = 'radio';
        }

        if ($type === 'text') {
            return self::fromArray(self::coerceTextToRadio($data));
        }

        if (! in_array($type, ['radio', 'checkbox'], true)) {
            return null;
        }

        $options = null;
        if (isset($data['options']) && is_array($data['options'])) {
            $parsed = [];
            foreach ($data['options'] as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $value = isset($opt['value']) && is_string($opt['value']) ? trim($opt['value']) : '';
                $optLabel = isset($opt['label']) && is_string($opt['label']) ? trim($opt['label']) : $value;
                if ($value === '') {
                    continue;
                }
                $parsed[] = ['value' => $value, 'label' => $optLabel !== '' ? $optLabel : $value];
            }
            $options = $parsed !== [] ? $parsed : null;
        }

        if (in_array($type, ['radio', 'checkbox'], true)) {
            $options = self::ensureOtherOption($options);
        }

        if (in_array($type, ['radio', 'checkbox'], true) && ($options === null || $options === [])) {
            return null;
        }

        $required = ! array_key_exists('required', $data) || filter_var($data['required'], FILTER_VALIDATE_BOOL);

        $placeholder = isset($data['placeholder']) && is_string($data['placeholder'])
            ? trim($data['placeholder'])
            : null;

        return new self(
            $id,
            $type,
            $label,
            $options,
            $required,
            $placeholder !== '' ? $placeholder : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function coerceTextToRadio(array $data): array
    {
        $data['type'] = 'radio';
        $data['options'] = [
            ['value' => 'unspecified', 'label' => '不确定 / 按常见情况处理'],
            ['value' => 'other', 'label' => '其他'],
        ];
        if (! array_key_exists('required', $data)) {
            $data['required'] = false;
        }

        return $data;
    }

    /**
     * @param list<array{value: string, label: string}>|null $options
     * @return list<array{value: string, label: string}>
     */
    private static function ensureOtherOption(?array $options): array
    {
        $options = $options ?? [];
        foreach ($options as $option) {
            if (self::isOtherOption($option['value'] ?? '', $option['label'] ?? '')) {
                return $options;
            }
        }

        $options[] = ['value' => 'other', 'label' => '其他'];

        return $options;
    }

    private static function isOtherOption(string $value, string $label): bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['other', 'other_custom', 'custom'], true) || str_ends_with($value, '_other')) {
            return true;
        }

        return str_contains($label, '其他');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'options' => $this->options,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'value' => $this->value,
        ];
    }
}
