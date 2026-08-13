<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Interface;

use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

interface StepStrategyBase
{
    public function supports(StepType $type): bool;

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase;

    /** @param non-empty-list<InvocationOutcome> $outcomes */
    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome;
}
