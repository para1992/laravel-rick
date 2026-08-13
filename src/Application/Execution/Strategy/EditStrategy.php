<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\EditStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class EditStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(private CompletionRequestFactory $requests) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'edit';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof EditStep) {
            throw new LogicException('Edit strategy received an incompatible step.');
        }

        return new InvocationStepPlan([$this->requests->create(
            'rick.step.edit',
            "Edit the following output in {$step->mode} mode.\n\n{$run->output()}",
            ResponseContract::Text,
            'edit',
            $step->modelPolicyId,
        )]);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        $responses = InvocationResponses::successful($outcomes);

        return StepOutcome::edited($run->output(), $responses[0]->text);
    }
}
