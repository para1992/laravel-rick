<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm;

use OutOfBoundsException;

final class ModelPolicyRegistry
{
    /** @var array<string, ModelPolicy> */
    private array $policies = [];

    /** @param iterable<ModelPolicy> $policies */
    public function __construct(iterable $policies = [])
    {
        foreach ($policies as $policy) {
            $this->register($policy);
        }
    }

    public function register(ModelPolicy $policy): void
    {
        $this->policies[$policy->id] = $policy;
    }

    public function has(string $id): bool
    {
        return isset($this->policies[$id]);
    }

    public function get(string $id): ModelPolicy
    {
        return $this->policies[$id]
            ?? throw new OutOfBoundsException("Model policy [{$id}] is not registered.");
    }
}
