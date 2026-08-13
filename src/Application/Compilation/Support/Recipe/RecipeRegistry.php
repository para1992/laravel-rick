<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe;

use OutOfBoundsException;
use Rick\Laravel\Application\Compilation\Support\Recipe\Interface\WorkflowRecipeBase;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

final class RecipeRegistry
{
    /** @var array<string, WorkflowRecipeBase> */
    private array $recipes = [];

    /** @param iterable<WorkflowRecipeBase> $recipes */
    public function __construct(iterable $recipes = [])
    {
        foreach ($recipes as $recipe) {
            $this->register($recipe);
        }
    }

    public function register(WorkflowRecipeBase $recipe): void
    {
        $this->recipes[$recipe->id()] = $recipe;
    }

    public function get(string $id): WorkflowRecipeBase
    {
        return $this->recipes[$id]
            ?? throw new OutOfBoundsException("Workflow recipe [{$id}] is not registered.");
    }

    /** @param array<string, mixed> $configuration */
    public function build(string $id, array $configuration = []): WorkflowDefinition
    {
        return $this->get($id)->build($configuration);
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = array_keys($this->recipes);
        sort($ids);

        return $ids;
    }
}
