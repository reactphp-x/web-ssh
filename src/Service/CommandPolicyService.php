<?php

declare(strict_types=1);

namespace App\Service;

use App\Policy\PolicyRuleLoader;
use App\Repository\CommandPolicyRepository;
use InvalidArgumentException;
use React\Promise\PromiseInterface;

final class CommandPolicyService
{
    public function __construct(
        private readonly CommandPolicyRepository $policies,
        private readonly PolicyRuleLoader $ruleLoader,
    ) {
    }

    /**
     * @return PromiseInterface<array<string, mixed>>
     */
    public function show(): PromiseInterface
    {
        return $this->policies->listAll()->then(function (array $items): array {
            return [
                'defaults' => $this->ruleLoader->load(),
                'policies' => $items,
            ];
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): PromiseInterface
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('策略名称不能为空。');
        }

        return $this->policies->upsert($input);
    }

    public function delete(int $id): PromiseInterface
    {
        return $this->policies->delete($id);
    }
}
