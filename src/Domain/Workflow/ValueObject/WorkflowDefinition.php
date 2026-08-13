<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\ValueObject;

use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

final readonly class WorkflowDefinition
{
    /** @param list<StepBase> $steps */
    public function __construct(
        public string $name,
        public string $version,
        public array $steps,
        public ?ResourceBudget $resourceBudget = null,
    ) {}
}
