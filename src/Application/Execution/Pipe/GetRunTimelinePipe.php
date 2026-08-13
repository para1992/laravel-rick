<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\ExecutionReadModelBase;
use Rick\Laravel\Application\Execution\Request\GetRunTimelineRequest;
use Rick\Laravel\Application\Execution\Result\GetRunTimelineResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GetRunTimelinePipe implements PipeBase
{
    public function __construct(private ExecutionReadModelBase $readModel) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(GetRunTimelineRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(GetRunTimelineRequest::class);

        return $next($parcel->put(new GetRunTimelineResult($this->readModel->timeline(
            $request->runId,
            $request->afterVersion,
        ))));
    }
}
