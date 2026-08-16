<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use LogicException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\StepFailureBase;
use Rick\Laravel\Application\Execution\Request\ContinueRunRequest;
use Rick\Laravel\Application\Execution\Result\ContinueRunResult;
use Rick\Laravel\Application\Execution\Result\ContinueRunStatus;
use Rick\Laravel\Application\Execution\Support\Dispatch\InvocationDispatch;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Factory\InvocationFactory;
use Rick\Laravel\Application\Execution\Support\Guard\ResourceBudgetGuard;
use Rick\Laravel\Application\Execution\Support\Interaction\PendingInteraction;
use Rick\Laravel\Application\Execution\Support\Planning\StepPlanning;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationReduction;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Exception\ResourceBudgetExceededException;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\StepExecutionStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

final readonly class ContinueRunPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private ExecutionRepositoryBase $executions,
        private TransactionBase $transactions,
        private DomainEventRecorder $events,
        private ExecutionBackendBase $backend,
        private ResourceBudgetGuard $budgets,
        private StepPlanning $planning,
        private InvocationFactory $invocations,
        private InvocationDispatch $dispatch,
        private PendingInteraction $interactions,
        private InvocationReduction $reduction,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(ContinueRunRequest::class)) {
            return $next($parcel);
        }

        $request = $parcel->get(ContinueRunRequest::class);

        $result = $this->transactions->run(
            fn (): ContinueRunResult => $this->transition($request->runId, true),
        );

        return $next($parcel->put($result));
    }

    public function advance(RunId $runId): ContinueRunResult
    {
        return $this->transactions->run(fn (): ContinueRunResult => $this->transition($runId, false));
    }

    private function transition(RunId $runId, bool $recordHandoff): ContinueRunResult
    {
        $run = $this->runs->get($runId);
        $snapshot = $run->snapshot();

        if ($snapshot->status === RunStatus::AwaitingInput) {
            return new ContinueRunResult(ContinueRunStatus::Waiting, $snapshot);
        }

        if ($snapshot->status->isTerminal() || $run->nextStep() === null) {
            return new ContinueRunResult(ContinueRunStatus::Terminal, $snapshot);
        }

        try {
            $this->budgets->assertCurrent($run->snapshot());
        } catch (ResourceBudgetExceededException $error) {
            return $this->failBudget($run, $error);
        }

        $step = $run->nextStep();
        $execution = $this->executions->findForStep($runId, $step->id());

        if ($execution === null) {
            return $this->plan($run, $step, $recordHandoff);
        }

        if (
            $execution->status() === StepExecutionStatus::Completed
            && $run->runningStepId()?->toString() === $step->id()->toString()
        ) {
            return $this->plan($run, $step, $recordHandoff);
        }

        if ($execution->status() === StepExecutionStatus::Failed) {
            return $this->failRun($run, $execution);
        }

        $invocations = $this->executions->invocationsFor($execution->id());
        $required = $execution->completionPolicy()->required($execution->expectedInvocations());
        $succeeded = $this->dispatch->successfulCount($execution, $invocations);
        $active = $this->dispatch->activeCount($execution, $invocations);
        $possible = $succeeded
            + $active
            + $this->dispatch->undispatchedCount($execution);
        $failed = $this->dispatch->failed($execution, $invocations);

        if ($possible < $required) {
            if ($active > 0) {
                return new ContinueRunResult(ContinueRunStatus::Waiting, $run->snapshot());
            }

            $version = $execution->version();
            $execution->fail(
                $failed?->errorCode() ?? 'invocation_quorum_unreachable',
                $failed?->errorMessage() ?? 'The invocation success quorum can no longer be reached.',
            );
            $this->executions->saveExecution($execution, $version);

            return $this->failRun($run, $execution);
        }

        $executionVersion = $execution->version();
        $nextInvocations = $this->dispatch->next($execution, $invocations);

        if ($nextInvocations !== []) {
            $this->executions->saveExecution($execution, $executionVersion);

            return $this->handoff(new ContinueRunResult(
                ContinueRunStatus::Dispatch,
                $run->snapshot(),
                array_map(
                    static fn ($invocation): InvocationId => $invocation->id(),
                    $nextInvocations,
                ),
            ), $execution->version(), $recordHandoff);
        }

        if (
            $this->dispatch->hasActive($execution, $invocations)
            || $this->dispatch->undispatchedCount($execution) > 0
        ) {
            return new ContinueRunResult(ContinueRunStatus::Waiting, $run->snapshot());
        }

        $succeeded = $this->dispatch->successfulCount($execution, $invocations);
        if ($succeeded < $required) {
            $version = $execution->version();
            $execution->fail(
                $failed?->errorCode() ?? 'invocation_quorum_unreachable',
                $failed?->errorMessage() ?? 'The invocation success quorum was not reached.',
            );
            $this->executions->saveExecution($execution, $version);

            return $this->failRun($run, $execution);
        }

        if ($invocations === []) {
            throw new LogicException('A completed step execution must contain invocations.');
        }

        $runVersion = $run->version();
        $execution->beginReduction();
        if ($succeeded < $execution->expectedInvocations()) {
            $run->recordDegradedStep(
                $step->id(),
                $execution->expectedInvocations(),
                $succeeded,
                $this->dispatch->failureCodes($execution, $invocations),
            );
        }
        $outcome = $this->reduction->reduce($step, $run->snapshot(), $invocations);
        if ($outcome->continuesStep) {
            $run->continueStep($step->id(), $outcome);
        } else {
            $run->completeStep($step->id(), $outcome);
        }
        $execution->complete();
        $this->executions->saveExecution($execution, $executionVersion);
        $this->runs->save($run, $runVersion);
        $this->events->record($run);

        return $this->handoff(new ContinueRunResult(
            $run->snapshot()->status->isTerminal()
                ? ContinueRunStatus::Terminal
                : ContinueRunStatus::Continue,
            $run->snapshot(),
        ), $run->version(), $recordHandoff);
    }

    private function plan(WorkflowRun $run, StepBase $step, bool $recordHandoff): ContinueRunResult
    {
        $runVersion = $run->version();
        if ($run->runningStepId() === null) {
            $run->beginStep($step->id());
        } elseif ($run->runningStepId()->toString() !== $step->id()->toString()) {
            throw new LogicException('Cannot plan a phase for a different running step.');
        }
        try {
            $plan = $this->planning->for($step, $run->snapshot());
        } catch (StepFailureBase $error) {
            $run->failStep($step->id(), $error->errorCode(), $error->getMessage());
            $this->runs->save($run, $runVersion);
            $this->events->record($run);

            return new ContinueRunResult(ContinueRunStatus::Terminal, $run->snapshot());
        }

        if ($plan instanceof ImmediateStepPlan) {
            $run->completeStep($step->id(), $plan->outcome);
            $this->runs->save($run, $runVersion);
            $this->events->record($run);

            return $this->handoff(new ContinueRunResult(
                $run->snapshot()->status->isTerminal()
                    ? ContinueRunStatus::Terminal
                    : ContinueRunStatus::Continue,
                $run->snapshot(),
            ), $run->version(), $recordHandoff);
        }

        if ($this->interactions->apply($run, $step, $plan)) {
            $this->runs->save($run, $runVersion);
            $this->events->record($run);

            return new ContinueRunResult(ContinueRunStatus::Waiting, $run->snapshot());
        }

        if (! $plan instanceof InvocationStepPlan) {
            throw new LogicException(sprintf('Unsupported step plan [%s].', $plan::class));
        }

        try {
            $this->budgets->assertCanDispatch($run->snapshot(), $plan->requests);
        } catch (ResourceBudgetExceededException $error) {
            $run->failStep($step->id(), $error->errorCode(), $error->getMessage());
            $this->runs->save($run, $runVersion);
            $this->events->record($run);

            return new ContinueRunResult(ContinueRunStatus::Terminal, $run->snapshot());
        }

        try {
            $batch = $this->invocations->create(
                $run->id(),
                $step->id(),
                $plan->requests,
                $plan->completionPolicy,
            );
        } catch (StepFailureBase $error) {
            $run->failStep($step->id(), $error->errorCode(), $error->getMessage());
            $this->runs->save($run, $runVersion);
            $this->events->record($run);

            return new ContinueRunResult(ContinueRunStatus::Terminal, $run->snapshot());
        }
        $run->reserveCalls(array_map(
            static fn ($request): string => $request->purpose,
            $plan->requests,
        ));
        $initialDispatch = $this->dispatch->next($batch->execution, $batch->invocations);
        $this->executions->add($batch->execution, $batch->invocations);
        $this->runs->save($run, $runVersion);
        $this->events->record($run);

        return $this->handoff(new ContinueRunResult(
            ContinueRunStatus::Dispatch,
            $run->snapshot(),
            array_map(
                static fn ($invocation): InvocationId => $invocation->id(),
                $initialDispatch,
            ),
        ), $batch->execution->version(), $recordHandoff);
    }

    private function failRun(WorkflowRun $run, StepExecution $execution): ContinueRunResult
    {
        $version = $run->version();
        $run->failStep(
            $execution->stepId(),
            $execution->errorCode() ?? 'step_execution_failed',
            $execution->errorMessage() ?? 'Step execution failed.',
        );
        $this->runs->save($run, $version);
        $this->events->record($run);

        return new ContinueRunResult(ContinueRunStatus::Terminal, $run->snapshot());
    }

    private function failBudget(
        WorkflowRun $run,
        ResourceBudgetExceededException $error,
    ): ContinueRunResult {
        $version = $run->version();
        $stepId = $run->runningStepId() ?? $run->nextStep()?->id()
            ?? throw new LogicException('A non-terminal run must have a step to fail.');

        if ($run->runningStepId() === null) {
            $run->beginStep($stepId);
        }

        $run->failStep($stepId, $error->errorCode(), $error->getMessage());
        $this->runs->save($run, $version);
        $this->events->record($run);

        return new ContinueRunResult(ContinueRunStatus::Terminal, $run->snapshot());
    }

    private function handoff(
        ContinueRunResult $result,
        int $transitionVersion,
        bool $recordHandoff,
    ): ContinueRunResult {
        if (! $recordHandoff) {
            return $result;
        }

        if ($result->status === ContinueRunStatus::Dispatch) {
            foreach ($result->invocations as $invocationId) {
                $this->backend->invoke($invocationId, $result->run->id, $transitionVersion);
            }
        } elseif ($result->status === ContinueRunStatus::Continue) {
            $this->backend->continue($result->run->id, $transitionVersion);
        }

        return $result;
    }
}
