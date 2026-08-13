<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\ExecutionReadModelBase;
use Rick\Laravel\Application\Execution\Request\ListRunsRequest;
use Rick\Laravel\Application\Execution\Result\ListRunsResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class ListRunsPipe implements PipeBase
{
    public function __construct(private ExecutionReadModelBase $readModel) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(ListRunsRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(ListRunsRequest::class);

        return $next($parcel->put(new ListRunsResult($this->readModel->runs(
            $request->cursor,
            $request->status,
            $request->limit,
        ))));
    }
}
