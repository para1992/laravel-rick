<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Interface;

use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;

interface CandidateSelectionBase
{
    public function select(
        StepBase $step,
        WorkflowRunSnapshot $run,
        CandidateId $candidateId,
    ): StepOutcome;
}
