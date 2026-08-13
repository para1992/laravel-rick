<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Plan;

use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Run\StepOutcome;

final readonly class ImmediateStepPlan implements StepPlanBase
{
    public function __construct(public StepOutcome $outcome) {}
}
