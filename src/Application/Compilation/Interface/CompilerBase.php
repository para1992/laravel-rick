<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Interface;

interface CompilerBase
{
    public function compile(DefinitionBase $definition): PlanBase;
}
