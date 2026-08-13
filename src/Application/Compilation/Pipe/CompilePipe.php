<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Pipe;

use Closure;
use Rick\Laravel\Application\Compilation\Interface\CompilerBase;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class CompilePipe implements PipeBase
{
    public function __construct(
        private CompilerBase $compiler,
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        $definition = $parcel->get(DefinitionBase::class);
        $plan = $this->compiler->compile($definition);

        return $next($parcel->put($plan));
    }
}
