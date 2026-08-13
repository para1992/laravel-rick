<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use LogicException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\SubmitInputRequest;
use Rick\Laravel\Application\Execution\Result\SubmitInputResult;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Execution\Interface\ExternalInputSubmissionBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class SubmitInputPipe implements PipeBase
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
        if (! $parcel->has(SubmitInputRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(SubmitInputRequest::class);
        $run = $this->transactions->run(function () use ($request) {
            $run = $this->runs->get($request->runId);
            $version = $run->version();
            $step = $run->nextStep()
                ?? throw new LogicException('Completed workflow has no pending external input.');
            $strategy = $this->strategies->for($step->type());
            if (! $strategy instanceof ExternalInputSubmissionBase) {
                throw new LogicException(sprintf(
                    'Step strategy [%s] does not accept external input.',
                    $strategy::class,
                ));
            }
            $outcome = $strategy->submit($step, $run->snapshot(), $request->key, $request->value);
            $run->resumeInput($step->id());
            $run->completeStep($step->id(), $outcome);
            $this->runs->save($run, $version);
            $this->events->record($run);
            $this->backend->continue($run->id(), $run->version());

            return $run;
        });

        return $next($parcel->put(new SubmitInputResult($run->snapshot())));
    }
}
