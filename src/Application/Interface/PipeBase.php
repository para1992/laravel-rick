<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Closure;
use Rick\Laravel\Domain\ValueObject\Parcel;

interface PipeBase
{
    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel;
}
