<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Handler;

use Illuminate\Pipeline\Pipeline;
use Rick\Laravel\Application\Interface\GateContractBase;
use Rick\Laravel\Application\Interface\ModuleBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final class Module extends HandlerBase implements ModuleBase
{
    /** @param iterable<PipeBase> $pipes */
    public function __construct(
        Pipeline $pipeline,
        iterable $pipes,
        private readonly GateContractBase $contract,
    ) {
        parent::__construct($pipeline, $pipes);
    }

    public function supports(Parcel $parcel): bool
    {
        foreach ($this->contract->inputs() as $input) {
            if (! $parcel->has($input)) {
                return false;
            }
        }

        return true;
    }
}
