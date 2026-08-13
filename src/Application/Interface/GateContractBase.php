<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Rick\Laravel\Domain\Interface\ParcelItemBase;

interface GateContractBase
{
    /** @return list<class-string<ParcelItemBase>> */
    public function inputs(): array;

    /** @return list<class-string<ParcelItemBase>> */
    public function outputs(): array;
}
