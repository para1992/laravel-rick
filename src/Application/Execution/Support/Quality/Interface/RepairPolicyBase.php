<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Interface;

use Rick\Laravel\Application\Execution\Support\Quality\Request\RepairDecisionRequest;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RepairDecision;

interface RepairPolicyBase
{
    public function id(): string;

    public function decide(RepairDecisionRequest $request): RepairDecision;
}
