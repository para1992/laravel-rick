<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Orchestration\Exception;

use RuntimeException;

final class ModuleNotFoundException extends RuntimeException
{
    public static function forParcel(): self
    {
        return new self('No Application module accepts the current Parcel.');
    }
}
