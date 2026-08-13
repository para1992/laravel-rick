<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

use LogicException;

final class ParcelItemNotFoundException extends LogicException
{
    public static function for(string $type): self
    {
        return new self(sprintf(
            'Parcel item matching [%s] was not found.',
            $type,
        ));
    }
}
