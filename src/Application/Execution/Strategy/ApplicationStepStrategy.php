<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Rick\Laravel\Application\Execution\Exception\ApplicationStepException;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\ApplicationStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\WorkflowState;
use Throwable;

/**
 * Executes an application-defined handler as an immediate, synchronous step.
 *
 * Semantics:
 * - An application step body is AT-LEAST-ONCE: it runs synchronously inside
 *   the run's transition. If the worker dies after the body runs but before
 *   the transition commits, the step re-runs on redelivery. External side
 *   effects (HTTP, emails, non-idempotent writes) must be made idempotent by
 *   the application.
 * - If the handler throws, the step fails cleanly: the exception is wrapped in
 *   ApplicationStepException (a StepFailureBase), so ContinueRunPipe marks the
 *   run failed with the step_failed timeline event. The raw exception never
 *   escapes the transaction.
 * - The handler resolves through the Laravel container (so it may have
 *   constructor dependencies) and is invoked with a single WorkflowState
 *   argument.
 */
final readonly class ApplicationStepStrategy implements StepStrategyBase
{
    public function __construct(private Container $container) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'application';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof ApplicationStep) {
            throw new LogicException('Application strategy received an incompatible step.');
        }

        $state = new WorkflowState($run->input, $run->artifacts, $run->id);

        $handler = $this->container->make($step->handlerClass);

        if (! is_callable($handler)) {
            throw new ApplicationStepException(sprintf(
                'Application step handler [%s] is not callable.',
                $step->handlerClass,
            ));
        }

        try {
            $handler($state);
        } catch (Throwable $error) {
            throw new ApplicationStepException($error->getMessage(), previous: $error);
        }

        return new ImmediateStepPlan(StepOutcome::completion(
            stepState: [
                'handler_class' => $step->handlerClass,
                'handler_version' => $step->handlerVersion,
            ],
            artifacts: $state->toArtifacts(),
        ));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        throw new LogicException('Application steps are immediate and cannot reduce invocations.');
    }
}
