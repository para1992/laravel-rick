<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Policy;

use Rick\Laravel\Application\Execution\Support\Quality\Interface\RepairPolicyBase;
use Rick\Laravel\Application\Execution\Support\Quality\Request\RepairDecisionRequest;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RepairDecision;

final readonly class FailRepairPolicy implements RepairPolicyBase
{
    public function id(): string
    {
        return 'fail';
    }

    public function decide(RepairDecisionRequest $request): RepairDecision
    {
        return $request->report->passed() ? RepairDecision::Accept : RepairDecision::Fail;
    }
}
