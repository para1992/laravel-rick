<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Policy;

use Rick\Laravel\Application\Execution\Support\Quality\Interface\RepairPolicyBase;
use Rick\Laravel\Application\Execution\Support\Quality\Request\RepairDecisionRequest;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RepairDecision;

final readonly class BoundedRepairPolicy implements RepairPolicyBase
{
    public function id(): string
    {
        return 'bounded_repair';
    }

    public function decide(RepairDecisionRequest $request): RepairDecision
    {
        if ($request->report->passed()) {
            return RepairDecision::Accept;
        }

        return $request->canRepair && $request->repairsUsed < $request->maxRepairs
            ? RepairDecision::Repair
            : RepairDecision::Fail;
    }
}
