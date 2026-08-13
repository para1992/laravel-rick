<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Gate\Exception;

use LogicException;
use Rick\Laravel\Application\Interface\GateContractBase;
use Throwable;

final class GateInputViolationException extends LogicException
{
    public static function for(GateContractBase $contract, string $input, Throwable $previous): self
    {
        return new self(sprintf(
            'Gate contract [%s] requires input [%s], but the parcel does not contain it.',
            $contract::class,
            $input,
        ), previous: $previous);
    }
}
