<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Handler;

use Closure;
use Illuminate\Pipeline\Pipeline;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;
use UnexpectedValueException;

abstract class HandlerBase implements PipeBase
{
    /** @var list<PipeBase> */
    private array $pipes;

    /** @param iterable<PipeBase> $pipes */
    public function __construct(
        private readonly Pipeline $pipeline,
        iterable $pipes,
    ) {
        $this->pipes = [];

        foreach ($pipes as $pipe) {
            $this->pipes[] = $pipe;
        }
    }

    final public function handle(Parcel $parcel): Parcel
    {
        $result = $this->pipeline
            ->send($parcel)
            ->through($this->pipes)
            ->via('process')
            ->thenReturn();

        if (! $result instanceof Parcel) {
            throw new UnexpectedValueException(sprintf(
                'Application pipeline returned [%s] instead of [%s].',
                get_debug_type($result),
                Parcel::class,
            ));
        }

        return $result;
    }

    /** @param Closure(Parcel): Parcel $next */
    final public function process(Parcel $parcel, Closure $next): Parcel
    {
        return $next($this->handle($parcel));
    }
}
