<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use LogicException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\RecoverRunRequest;
use Rick\Laravel\Application\Execution\Result\RecoverRunResult;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Factory\InvocationFactory;
use Rick\Laravel\Application\Execution\Support\Guard\ResourceBudgetGuard;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class RecoverRunPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private ExecutionRepositoryBase $executions,
        private TransactionBase $transactions,
        private DomainEventRecorder $events,
        private ExecutionBackendBase $backend,
        private ResourceBudgetGuard $budgets,
        private InvocationFactory $invocations,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(RecoverRunRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(RecoverRunRequest::class);
        $result = $this->transactions->run(fn (): ?RecoverRunResult => $this->recover($request));
        if ($result === null) {
            $winner = $this->runs->findRecovery($request->parentRunId, $request->action)
                ?? throw new LogicException('Recovery idempotency winner is not readable after commit.');
            $result = new RecoverRunResult($winner->snapshot(), 0, 0, 0, true);
        }

        return $next($parcel->put($result));
    }

    private function recover(RecoverRunRequest $request): ?RecoverRunResult
    {
        $existing = $this->runs->findRecovery($request->parentRunId, $request->action);
        if ($existing !== null) {
            return new RecoverRunResult($existing->snapshot(), 0, 0, 0, true);
        }

        $parent = $this->runs->get($request->parentRunId);
        $child = WorkflowRun::recover(
            $request->childRunId,
            $parent,
            $request->action,
            $request->callLimit ?? $parent->snapshot()->callLimit,
        );
        $step = $child->nextStep()
            ?? throw new InvalidStateTransitionException('Recovery child has no failed step to resume.');
        $parentExecution = $this->executions->findForStep($parent->id(), $step->id())
            ?? throw new InvalidStateTransitionException('Failed step has no persisted execution to recover.');
        $sources = $this->executions->invocationsFor($parentExecution->id());
        if ($sources === []) {
            throw new InvalidStateTransitionException('Failed step has no persisted invocations to recover.');
        }
        $sources = $this->orderedSources($parentExecution, $sources);
        $parentStepState = $parent->snapshot()->stepState($step->id()->toString());
        $rerunSuccessful = $request->action === RunRecoveryAction::ForkFailedStep
            || ($parentStepState['phase'] ?? null) === 'failed';

        $child->beginStep($step->id());
        $successful = count(array_filter(
            $sources,
            static fn (LlmInvocation $source): bool => $source->status() === InvocationStatus::Succeeded,
        ));
        if ($request->action === RunRecoveryAction::ContinueSuccessful && $successful < 1) {
            throw new InvalidStateTransitionException('Recovery cannot continue without a successful invocation.');
        }
        $policy = $request->action === RunRecoveryAction::ContinueSuccessful
            ? InvocationCompletionPolicy::minimumSuccessful($successful)
            : $parentExecution->completionPolicy();
        $batch = $this->invocations->create(
            $child->id(),
            $step->id(),
            array_map(
                static fn (LlmInvocation $source) => $source->request(),
                $sources,
            ),
            $policy,
        );

        $recovered = [];
        $pending = [];
        $reused = 0;
        $copiedFailures = 0;
        foreach ($batch->invocations as $target) {
            $source = $sources[$target->index()]
                ?? throw new LogicException('Recovery source invocation index is missing.');
            if (
                ! $rerunSuccessful
                && $source->status() === InvocationStatus::Succeeded
            ) {
                $recovered[] = LlmInvocation::reused(
                    $target->id(),
                    $target->executionId(),
                    $target->runId(),
                    $target->stepId(),
                    $target->index(),
                    $target->request(),
                    $source,
                );
                $reused++;

                continue;
            }
            if ($request->action === RunRecoveryAction::ContinueSuccessful) {
                $recovered[] = LlmInvocation::unavailableFrom(
                    $target->id(),
                    $target->executionId(),
                    $target->runId(),
                    $target->stepId(),
                    $target->index(),
                    $target->request(),
                    $source,
                );
                $copiedFailures++;

                continue;
            }
            $recovered[] = $target;
            $pending[] = $target;
        }

        if ($pending !== []) {
            $requests = array_map(
                static fn (LlmInvocation $invocation) => $invocation->request(),
                $pending,
            );
            $this->budgets->assertCanDispatch($child->snapshot(), $requests);
            $child->reserveCalls(array_map(
                static fn (LlmInvocation $invocation): string => $invocation->request()->purpose,
                $pending,
            ));
        }
        $batch->execution->markDispatched(count($recovered));

        if (! $this->runs->addRecovery($child)) {
            return null;
        }
        $this->executions->add($batch->execution, $recovered);
        $this->events->record($child);
        foreach ($pending as $invocation) {
            $this->backend->invoke($invocation->id(), $child->id(), $batch->execution->version());
        }
        if ($pending === []) {
            $this->backend->continue($child->id(), $child->version());
        }

        return new RecoverRunResult(
            $child->snapshot(),
            $reused,
            count($pending),
            $copiedFailures,
        );
    }

    /**
     * @param  non-empty-list<LlmInvocation>  $sources
     * @return non-empty-list<LlmInvocation>
     */
    private function orderedSources(StepExecution $execution, array $sources): array
    {
        if (count($sources) !== $execution->expectedInvocations()) {
            throw new LogicException('Recovery source cardinality differs from the persisted execution.');
        }

        $indexed = [];
        foreach ($sources as $source) {
            if (array_key_exists($source->index(), $indexed)) {
                throw new LogicException('Recovery source invocation indices must be unique.');
            }
            $indexed[$source->index()] = $source;
        }
        ksort($indexed);
        if (array_keys($indexed) !== range(0, count($sources) - 1)) {
            throw new LogicException('Recovery source invocation indices must be contiguous.');
        }

        foreach ($indexed as $index => $source) {
            if ($index >= $execution->dispatchedInvocations()) {
                if ($source->status() !== InvocationStatus::Pending || $source->attempts() !== 0) {
                    throw new InvalidStateTransitionException(
                        'An undispatched recovery source must remain pending without attempts.',
                    );
                }

                continue;
            }
            if (! in_array($source->status(), [InvocationStatus::Succeeded, InvocationStatus::Failed], true)) {
                throw new InvalidStateTransitionException(
                    'Every dispatched recovery source must be terminal.',
                );
            }
        }

        return array_values($indexed);
    }
}
