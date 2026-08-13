<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Exception;

use LogicException;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;

final class StrategyNotFoundException extends LogicException
{
    public static function for(DefinitionBase $definition): self
    {
        return new self(sprintf(
            'No compilation strategy supports definition [%s].',
            $definition::class,
        ));
    }
}
