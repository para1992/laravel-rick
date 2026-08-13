<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Interface;

use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

interface InvocationReductionBase
{
    /** @param non-empty-list<InvocationOutcome> $outcomes */
    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome;
}
