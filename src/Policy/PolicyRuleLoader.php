<?php

declare(strict_types=1);

namespace App\Policy;

use App\Repository\CommandPolicyRepository;

final class PolicyRuleLoader
{
    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    private static ?int $defaultsMtime = null;

    public function __construct(
        private readonly ?CommandPolicyRepository $policies = null,
        private readonly string $defaultsPath = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(?PolicyContext $context = null): array
    {
        $rules = $this->defaults();

        if ($this->policies === null || $context === null) {
            return $rules;
        }

        $overlays = $this->policies->findOverlaysForContext(
            $context->hostId,
            $context->hostGroupId,
        );

        foreach ($overlays as $overlay) {
            $rules = $this->mergeRules($rules, $overlay);
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $path = $this->defaultsPath !== ''
            ? $this->defaultsPath
            : dirname(__DIR__, 2) . '/config/command_policy.defaults.php';

        $mtime = is_file($path) ? (int) filemtime($path) : null;
        if (self::$defaults !== null && self::$defaultsMtime === $mtime) {
            return self::$defaults;
        }

        /** @var array<string, mixed> $defaults */
        $defaults = require $path;
        self::$defaults = $defaults;
        self::$defaultsMtime = $mtime;

        return $defaults;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function mergeRules(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (!is_array($value) || !isset($base[$key]) || !is_array($base[$key])) {
                $base[$key] = $value;
                continue;
            }

            if ($this->isList($value)) {
                $base[$key] = array_values(array_unique(array_merge($base[$key], $value), SORT_REGULAR));
                continue;
            }

            /** @var array<string, mixed> $existing */
            $existing = $base[$key];
            /** @var array<string, mixed> $incoming */
            $incoming = $value;
            $base[$key] = array_merge($existing, $incoming);
        }

        return $base;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    public static function resetDefaultsCache(): void
    {
        self::$defaults = null;
        self::$defaultsMtime = null;
    }
}
