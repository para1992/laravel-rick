<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\ExecutionReadModelBase;
use Rick\Laravel\Application\Execution\Request\GetDeliverySnapshotRequest;
use Rick\Laravel\Application\Execution\Result\GetDeliverySnapshotResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GetDeliverySnapshotPipe implements PipeBase
{
    public function __construct(private ExecutionReadModelBase $readModel) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(GetDeliverySnapshotRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(GetDeliverySnapshotRequest::class);

        return $next($parcel->put(new GetDeliverySnapshotResult(
            $this->readModel->delivery($request->runId),
        )));
    }
}
