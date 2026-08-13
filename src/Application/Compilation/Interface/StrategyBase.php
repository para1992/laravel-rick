<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Interface;

interface StrategyBase
{
    public function supports(DefinitionBase $definition): bool;

    public function compile(DefinitionBase $definition): PlanBase;
}
