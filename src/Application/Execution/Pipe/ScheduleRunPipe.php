<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowPlan;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\ScheduleRunRequest;
use Rick\Laravel\Application\Execution\Result\ScheduleRunResult;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class ScheduleRunPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private TransactionBase $transactions,
        private DomainEventRecorder $events,
        private ExecutionBackendBase $backend,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(ScheduleRunRequest::class)) {
            return $next($parcel);
        }

        $plan = $parcel->get(WorkflowPlan::class);
        $request = $parcel->get(ScheduleRunRequest::class);
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
            $this->backend->continue($run->id(), $run->version());
        });

        return $next($parcel->put(new ScheduleRunResult($run)));
    }
}
