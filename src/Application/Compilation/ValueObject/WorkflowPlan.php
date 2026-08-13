<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\ValueObject;

use Rick\Laravel\Application\Compilation\Interface\PlanBase;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;

final readonly class WorkflowPlan implements PlanBase
{
    public function __construct(
        public CompiledWorkflow $workflow,
    ) {}
}
