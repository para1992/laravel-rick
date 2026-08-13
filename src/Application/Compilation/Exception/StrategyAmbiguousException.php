<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Exception;

use LogicException;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;

final class StrategyAmbiguousException extends LogicException
{
    public static function for(DefinitionBase $definition, int $count): self
    {
        return new self(sprintf(
            'Definition [%s] is supported by [%d] compilation strategies; exactly one is required.',
            $definition::class,
            $count,
        ));
    }
}
