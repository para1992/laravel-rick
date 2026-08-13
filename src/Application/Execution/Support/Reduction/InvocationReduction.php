<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Reduction;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

final readonly class InvocationReduction
{
    public function __construct(private StepStrategyRegistry $strategies) {}

    /** @param non-empty-list<LlmInvocation> $invocations */
    public function reduce(
        StepBase $step,
        WorkflowRunSnapshot $run,
        array $invocations,
    ): StepOutcome {
        $outcomes = array_map(
            static fn (LlmInvocation $invocation): InvocationOutcome => InvocationOutcome::fromInvocation(
                $invocation,
            ),
            $invocations,
        );
        $strategy = $this->strategies->for($step->type());

        if (! $strategy instanceof InvocationReductionBase) {
            throw new LogicException(sprintf(
                'Step strategy [%s] cannot reduce invocation responses.',
                $strategy::class,
            ));
        }

        return $strategy->reduce($step, $run, $outcomes);
    }
}
