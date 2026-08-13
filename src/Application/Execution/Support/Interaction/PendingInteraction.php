<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Interaction;

use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Plan\AwaitingCandidateSelectionPlan;
use Rick\Laravel\Domain\Execution\Plan\AwaitingExternalInputPlan;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

final readonly class PendingInteraction
{
    public function apply(WorkflowRun $run, StepBase $step, StepPlanBase $plan): bool
    {
        if ($plan instanceof AwaitingCandidateSelectionPlan) {
            $run->awaitInput($step->id(), $plan->metadata);

            return true;
        }

        if (! $plan instanceof AwaitingExternalInputPlan) {
            return false;
        }

        $run->continueStep($step->id(), StepOutcome::continuation([
            'pending_input' => [
                'key' => $plan->key,
                'prompt' => $plan->prompt,
                'schema' => $plan->schema,
            ],
        ]));
        $run->awaitExternalInput($step->id(), $plan->key, $plan->prompt, $plan->schema);

        return true;
    }
}
