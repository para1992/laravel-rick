<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Gate\Exception;

use LogicException;
use Rick\Laravel\Application\Interface\GateContractBase;
use Throwable;

final class GateOutputViolationException extends LogicException
{
    public static function for(GateContractBase $contract, string $output, Throwable $previous): self
    {
        return new self(sprintf(
            'Gate contract [%s] guarantees output [%s], but the parcel does not contain it.',
            $contract::class,
            $output,
        ), previous: $previous);
    }
}
