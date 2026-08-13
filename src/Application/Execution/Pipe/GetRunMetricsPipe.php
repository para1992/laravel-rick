<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\GetRunMetricsRequest;
use Rick\Laravel\Application\Execution\Result\GetRunMetricsResult;
use Rick\Laravel\Application\Execution\Support\Metrics\RunMetricsProjection;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GetRunMetricsPipe implements PipeBase
{
    public function __construct(
        private RunRepositoryBase $runs,
        private RunMetricsProjection $metrics,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(GetRunMetricsRequest::class)) {
            return $next($parcel);
        }
        $request = $parcel->get(GetRunMetricsRequest::class);
        $run = $this->runs->get($request->runId)->snapshot();

        return $next($parcel->put(new GetRunMetricsResult($this->metrics->for($run))));
    }
}
