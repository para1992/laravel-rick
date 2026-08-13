<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Contract;

use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Application\Compilation\Interface\PlanBase;
use Rick\Laravel\Application\Interface\GateContractBase;

final class CompilationGateContract implements GateContractBase
{
    public function inputs(): array
    {
        return [DefinitionBase::class];
    }

    public function outputs(): array
    {
        return [PlanBase::class];
    }
}
