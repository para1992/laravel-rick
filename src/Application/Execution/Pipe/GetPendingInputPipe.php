<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\GetPendingInputRequest;
use Rick\Laravel\Application\Execution\Result\GetPendingInputResult;
use Rick\Laravel\Application\Execution\Support\Interaction\PendingInteractionProjection;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GetPendingInputPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private PendingInteractionProjection $interactions,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(GetPendingInputRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(GetPendingInputRequest::class);

        return $next($parcel->put(new GetPendingInputResult(
            $this->interactions->input($this->runs->get($request->runId)),
        )));
    }
}
