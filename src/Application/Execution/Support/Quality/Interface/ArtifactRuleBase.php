<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Interface;

use Rick\Laravel\Application\Execution\Support\Quality\Result\RuleResult;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

interface ArtifactRuleBase
{
    public function id(): string;

    public function evaluate(Artifact $artifact, WorkflowRunSnapshot $run): RuleResult;
}
