<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\GetRunSnapshotRequest;
use Rick\Laravel\Application\Execution\Result\GetRunSnapshotResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GetRunSnapshotPipe implements PipeBase
{
    public function __construct(private RunRepositoryBase $runs) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(GetRunSnapshotRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(GetRunSnapshotRequest::class);

        return $next($parcel->put(new GetRunSnapshotResult(
            $this->runs->get($request->runId)->snapshot(),
        )));
    }
}
