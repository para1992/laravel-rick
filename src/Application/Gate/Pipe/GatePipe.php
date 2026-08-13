<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Gate\Pipe;

use Closure;
use Rick\Laravel\Application\Gate\Exception\GateInputViolationException;
use Rick\Laravel\Application\Gate\Exception\GateOutputViolationException;
use Rick\Laravel\Application\Interface\GateContractBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\Exception\ParcelItemAmbiguousException;
use Rick\Laravel\Domain\Exception\ParcelItemNotFoundException;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class GatePipe implements PipeBase
{
    public function __construct(
        private GateContractBase $contract,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        foreach ($this->contract->inputs() as $input) {
            try {
                $parcel->get($input);
            } catch (ParcelItemNotFoundException|ParcelItemAmbiguousException $error) {
                throw GateInputViolationException::for($this->contract, $input, $error);
            }
        }

        $result = $next($parcel);

        foreach ($this->contract->outputs() as $output) {
            try {
                $result->get($output);
            } catch (ParcelItemNotFoundException|ParcelItemAmbiguousException $error) {
                throw GateOutputViolationException::for($this->contract, $output, $error);
            }
        }

        return $result;
    }
}
