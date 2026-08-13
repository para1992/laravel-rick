<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Planning;

use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

final readonly class StepPlanning
{
    public function __construct(private StepStrategyRegistry $strategies) {}

    public function for(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        return $this->strategies->for($step->type())->plan($step, $run);
    }
}
