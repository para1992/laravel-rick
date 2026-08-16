<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\GetRunProgressRequest;
use Rick\Laravel\Application\Execution\Result\GetRunProgressResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GetRunProgressPipe implements PipeBase
{
    public function __construct(private RunRepositoryBase $runs) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(GetRunProgressRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(GetRunProgressRequest::class);

        return $next($parcel->put(new GetRunProgressResult(
            $this->runs->get($request->runId)->progress(),
        )));
    }
}
