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
use Rick\Laravel\Domain\Workflow\Step\DefineDodStep;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class DefineDodStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(private CompletionRequestFactory $requests) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::defineDod()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof DefineDodStep) {
            throw new LogicException('DoD strategy received an incompatible step.');
        }

        return new InvocationStepPlan([$this->requests->create(
            'rick.step.define_dod',
            "Define concrete completion criteria for this task:\n{$run->task}",
            ResponseContract::DefinitionOfDone,
            'define_dod',
            $step->modelPolicyId,
        )]);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        $responses = InvocationResponses::successful($outcomes);
        $response = $responses[0];
        $criteria = $response->structured['criteria'] ?? null;

        return StepOutcome::dodDefined(
            is_array($criteria)
                ? DefinitionOfDone::structured(['criteria' => $criteria])
                : DefinitionOfDone::fromString($response->text),
        );
    }
}
