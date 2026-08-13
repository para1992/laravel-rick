<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use LogicException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\SelectCandidateRequest;
use Rick\Laravel\Application\Execution\Result\SelectCandidateResult;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Execution\Interface\CandidateSelectionBase;
use Rick\Laravel\Domain\Run\CandidateSelection;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class SelectCandidatePipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private TransactionBase $transactions,
        private DomainEventRecorder $events,
        private ExecutionBackendBase $backend,
        private StepStrategyRegistry $strategies,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(SelectCandidateRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(SelectCandidateRequest::class);
        $run = $this->transactions->run(function () use ($request) {
            $run = $this->runs->get($request->runId);
            $version = $run->version();
            $step = $run->nextStep()
                ?? throw new LogicException('Completed workflow has no pending candidate review.');
            $strategy = $this->strategies->for($step->type());
            if (! $strategy instanceof CandidateSelectionBase) {
                throw new LogicException(sprintf(
                    'Step strategy [%s] cannot select candidates.',
                    $strategy::class,
                ));
            }
            $outcome = $strategy->select($step, $run->snapshot(), $request->candidateId);
            $run->resumeInput($step->id());
            if ($outcome->continuesStep) {
                $run->continueStep($step->id(), $outcome);
            } else {
                $run->completeStep($step->id(), $outcome);
            }
            $this->runs->save($run, $version);
            $this->events->record($run);
            $this->backend->continue($run->id(), $run->version());

            return $run;
        });

        return $next($parcel->put(new SelectCandidateResult(new CandidateSelection(
            $run->snapshot(),
            continuationQueued: true,
        ))));
    }
}
