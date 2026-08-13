<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Plan;

use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;

final readonly class AwaitingCandidateSelectionPlan implements StepPlanBase
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public array $metadata = []) {}
}
