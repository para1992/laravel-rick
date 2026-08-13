<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class ResolveStrategy implements StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::resolve()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        return $step instanceof ResolveStep
            ? new ImmediateStepPlan(StepOutcome::resolved($step->task, $step->dod))
            : throw new LogicException('Resolve strategy received an incompatible step.');
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Resolve is an immediate step and cannot reduce invocations.');
    }
}
