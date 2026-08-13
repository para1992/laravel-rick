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
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class OutputGlueStrategy implements StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::outputGlue()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof OutputGlueStep) {
            throw new LogicException('Output glue strategy received an incompatible step.');
        }

        $lastCandidate = array_key_last($run->acceptedCandidates);
        $output = $step->artifactKey !== null
            ? $run->artifact($step->artifactKey)->content
            : ($lastCandidate === null
                ? $run->output()
                : $run->acceptedCandidates[$lastCandidate]->content);

        return new ImmediateStepPlan(StepOutcome::outputProduced($output));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Output glue is an immediate step and cannot reduce invocations.');
    }
}
