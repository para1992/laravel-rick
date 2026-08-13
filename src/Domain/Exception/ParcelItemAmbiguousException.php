<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

use LogicException;

final class ParcelItemAmbiguousException extends LogicException
{
    public static function for(string $type, int $count): self
    {
        return new self(sprintf(
            'Parcel contains [%d] items matching [%s]; exactly one is required.',
            $count,
            $type,
        ));
    }
}
