<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class RawPromptStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === 'raw_prompt';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof RawPromptStep) {
            throw new LogicException('Raw prompt strategy received an incompatible step.');
        }

        return new InvocationStepPlan([new CompletionRequest(
            [new Message('user', $step->prompt)],
            ResponseContract::Text,
            'raw_prompt',
            $step->modelPolicyId,
            metadata: ['raw_prompt' => true],
        )]);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof RawPromptStep) {
            throw new LogicException('Raw prompt strategy received an incompatible step.');
        }
        $responses = InvocationResponses::successful($outcomes);

        return StepOutcome::outputProduced($responses[0]->text);
    }
}
