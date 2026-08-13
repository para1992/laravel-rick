<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Operation;

use OutOfBoundsException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\LlmOperationBase;

final class LlmOperationRegistry
{
    /** @var array<string, LlmOperationBase> */
    private array $operations = [];

    /** @param iterable<LlmOperationBase> $operations */
    public function __construct(iterable $operations = [])
    {
        foreach ($operations as $operation) {
            $this->register($operation);
        }
    }

    public function register(LlmOperationBase $operation): void
    {
        $definition = $operation->definition();
        $this->operations[$definition->id.'@'.$definition->version] = $operation;
    }

    public function get(string $id, ?string $version = null): LlmOperationBase
    {
        if ($version !== null) {
            return $this->operations[$id.'@'.$version]
                ?? throw new OutOfBoundsException("LLM operation [{$id}@{$version}] is not registered.");
        }
        $matches = array_values(array_filter(
            $this->operations,
            static fn (LlmOperationBase $operation): bool => $operation->definition()->id === $id,
        ));
        if ($matches === []) {
            throw new OutOfBoundsException("LLM operation [{$id}] is not registered.");
        }
        usort(
            $matches,
            static fn (LlmOperationBase $left, LlmOperationBase $right): int => version_compare(
                $right->definition()->version,
                $left->definition()->version,
            ),
        );

        return $matches[0];
    }
}
