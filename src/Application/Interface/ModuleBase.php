<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Rick\Laravel\Domain\ValueObject\Parcel;

interface ModuleBase
{
    public function supports(Parcel $parcel): bool;

    public function handle(Parcel $parcel): Parcel;
}
