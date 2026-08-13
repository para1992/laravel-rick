<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowPlan;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\RunWorkflowRequest;
use Rick\Laravel\Application\Execution\Result\ContinueRunStatus;
use Rick\Laravel\Application\Execution\Result\RunWorkflowResult;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class RunWorkflowPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private TransactionBase $transactions,
        private DomainEventRecorder $events,
        private ContinueRunPipe $continuations,
        private ExecuteInvocationPipe $invocations,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(RunWorkflowRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(RunWorkflowRequest::class);
        $plan = $parcel->get(WorkflowPlan::class);
        $run = WorkflowRun::start(
            $request->runId,
            $plan->workflow,
            $request->input,
            $request->callLimit,
            $request->startedAt,
        );
        $this->transactions->run(function () use ($run): void {
            $this->runs->add($run);
            $this->events->record($run);
        });

        do {
            $transition = $this->continuations->advance($run->id());
            foreach ($transition->invocations as $invocationId) {
                $this->invocations->execute($invocationId);
            }
        } while (in_array($transition->status, [
            ContinueRunStatus::Continue,
            ContinueRunStatus::Dispatch,
        ], true));

        return $next($parcel->put(new RunWorkflowResult($this->runs->get($run->id())->snapshot())));
    }
}
