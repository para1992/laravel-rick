<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\OperationContext;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\LlmOperationStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class OperationStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(private LlmOperationRegistry $operations) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'operation';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof LlmOperationStep) {
            throw new LogicException('Operation strategy received an incompatible step.');
        }
        $operation = $this->operations->get($step->operationId, $step->operationVersion);

        return new InvocationStepPlan($operation->requests($this->context($step, $run)));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof LlmOperationStep) {
            throw new LogicException('Operation strategy received an incompatible step.');
        }
        $responses = InvocationResponses::successful($outcomes);

        $operation = $this->operations->get($step->operationId, $step->operationVersion);

        return StepOutcome::artifactsProduced(
            $operation->reduce($this->context($step, $run), $responses)->artifacts,
        );
    }

    private function context(
        LlmOperationStep $step,
        WorkflowRunSnapshot $run,
    ): OperationContext {
        $inputs = [];
        foreach ($step->inputKeys as $key) {
            $inputs[$key] = $run->artifact($key);
        }

        return new OperationContext($run, $inputs, $step->outputKey, $step->parameters);
    }
}
