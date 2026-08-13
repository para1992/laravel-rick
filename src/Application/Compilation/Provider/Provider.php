<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Provider;

use Rick\Laravel\Application\Compilation\Contract\CompilationGateContract;
use Rick\Laravel\Application\Compilation\Interface\CompilerBase;
use Rick\Laravel\Application\Compilation\Pipe\CompilePipe;
use Rick\Laravel\Application\Compilation\Strategy\WorkflowStrategy;
use Rick\Laravel\Application\Compilation\Support\Compiler\Compiler;
use Rick\Laravel\Application\Interface\GateContractBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Provider\ProviderBase;

final class Provider extends ProviderBase
{
    public const string STRATEGY_TAG = 'rick.compilation.strategies';

    protected function singletons(): array
    {
        return [
            CompilerBase::class => Compiler::class,
        ];
    }

    protected function taggedDependencies(): array
    {
        return [
            Compiler::class => [
                '$strategies' => self::STRATEGY_TAG,
            ],
        ];
    }

    protected function tags(): array
    {
        return [
            self::STRATEGY_TAG => [
                WorkflowStrategy::class,
            ],
        ];
    }

    /** @return class-string<GateContractBase> */
    protected function gateContract(): string
    {
        return CompilationGateContract::class;
    }

    /** @return list<class-string<PipeBase>> */
    protected function pipes(): array
    {
        return [
            CompilePipe::class,
        ];
    }
}
