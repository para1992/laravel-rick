<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Contract;

use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionResultBase;
use Rick\Laravel\Application\Interface\GateContractBase;

final class ExecutionGateContract implements GateContractBase
{
    public function inputs(): array
    {
        return [ExecutionRequestBase::class];
    }

    public function outputs(): array
    {
        return [ExecutionResultBase::class];
    }
}
