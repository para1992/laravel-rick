<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Request;

use Rick\Laravel\Application\Execution\Support\Quality\Result\QualityReport;

final readonly class RepairDecisionRequest
{
    public function __construct(
        public QualityReport $report,
        public int $repairsUsed,
        public int $maxRepairs,
        public bool $canRepair,
    ) {}
}
