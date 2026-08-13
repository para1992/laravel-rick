<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Result;

use Rick\Laravel\Application\Execution\Interface\ExecutionResultBase;
use Rick\Laravel\Domain\Metrics\ValueObject\RunMetrics;

final readonly class GetRunMetricsResult implements ExecutionResultBase
{
    public function __construct(public RunMetrics $metrics) {}
}
