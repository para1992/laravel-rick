<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Interface;

use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

interface ExternalInputSubmissionBase
{
    public function submit(
        StepBase $step,
        WorkflowRunSnapshot $run,
        string $key,
        mixed $value,
    ): StepOutcome;
}
