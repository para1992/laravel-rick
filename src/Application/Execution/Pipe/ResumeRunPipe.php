<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\ResumeRunRequest;
use Rick\Laravel\Application\Execution\Result\ResumeRunResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class ResumeRunPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private ExecutionBackendBase $backend,
        private TransactionBase $transactions,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(ResumeRunRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(ResumeRunRequest::class);
        $run = $this->transactions->run(function () use ($request) {
            $run = $this->runs->get($request->runId);
            if (! $run->snapshot()->status->isTerminal()) {
                $this->backend->continue($request->runId, $run->version());
            }

            return $run;
        });

        return $next($parcel->put(new ResumeRunResult($run->snapshot())));
    }
}
