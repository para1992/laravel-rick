<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\ValueObject;

use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

final readonly class CompiledWorkflow
{
    /** @param list<StepBase> $steps */
    public function __construct(
        public string $name,
        public string $version,
        public array $steps,
        public ?ResourceBudget $resourceBudget = null,
    ) {}

    public function stepAt(int $position): ?StepBase
    {
        return $this->steps[$position] ?? null;
    }

    public function count(): int
    {
        return count($this->steps);
    }
}
