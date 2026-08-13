<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Orchestration\Pipe;

use Closure;
use Rick\Laravel\Application\Interface\ModuleBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Orchestration\Exception\ModuleNotFoundException;
use Rick\Laravel\Domain\ValueObject\Parcel;

final class DispatchPipe implements PipeBase
{
    /** @var list<ModuleBase> */
    private array $modules;

    /** @param iterable<ModuleBase> $modules */
    public function __construct(iterable $modules)
    {
        $this->modules = [];

        foreach ($modules as $module) {
            $this->modules[] = $module;
        }
    }

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        $handled = false;

        foreach ($this->modules as $module) {
            if (! $module->supports($parcel)) {
                continue;
            }

            $parcel = $module->handle($parcel);
            $handled = true;
        }

        if (! $handled) {
            throw ModuleNotFoundException::forParcel();
        }

        return $next($parcel);
    }
}
