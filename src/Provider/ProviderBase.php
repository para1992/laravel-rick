<?php

declare(strict_types=1);

namespace Rick\Laravel\Provider;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Rick\Laravel\Application\Gate\Pipe\GatePipe;
use Rick\Laravel\Application\Handler\Module;
use Rick\Laravel\Application\Interface\GateContractBase;
use Rick\Laravel\Application\Interface\PipeBase;

abstract class ProviderBase extends ServiceProvider
{
    public const string ORCHESTRATION_MODULE_TAG = 'rick.orchestration.modules';

    final public function register(): void
    {
        foreach ($this->bindings() as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }

        foreach ($this->singletons() as $abstract => $concrete) {
            $this->app->singleton($abstract, $concrete);
        }

        foreach ($this->scoped() as $abstract => $concrete) {
            $this->app->scoped($abstract, $concrete);
        }

        foreach ($this->tags() as $tag => $abstracts) {
            $this->app->tag($abstracts, $tag);
        }

        foreach ($this->taggedDependencies() as $concrete => $dependencies) {
            foreach ($dependencies as $parameter => $tag) {
                $this->app
                    ->when($concrete)
                    ->needs($parameter)
                    ->giveTagged($tag);
            }
        }

        $contract = $this->gateContract();
        $gate = $this->gateBinding();
        $pipeTag = $this->pipeTag();
        $module = $this->moduleBinding();

        $this->app->bind($contract, $contract);
        $this->app->bind(
            $gate,
            static fn (Application $app): GatePipe => new GatePipe(
                self::contract($app, $contract),
            ),
        );
        $this->app->tag(
            [
                $gate,
                ...$this->pipes(),
            ],
            $pipeTag,
        );
        $this->app->bind(
            $module,
            static fn (Application $app): Module => new Module(
                new Pipeline($app),
                self::resolvedPipes($app, $pipeTag),
                self::contract($app, $contract),
            ),
        );
        $this->app->tag($module, self::ORCHESTRATION_MODULE_TAG);
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [];
    }

    /** @return array<class-string, class-string> */
    protected function singletons(): array
    {
        return [];
    }

    /** @return array<class-string, class-string> */
    protected function scoped(): array
    {
        return [];
    }

    /** @return array<string, list<class-string>> */
    protected function tags(): array
    {
        return [];
    }

    /** @return array<class-string, array<string, string>> */
    protected function taggedDependencies(): array
    {
        return [];
    }

    /** @return class-string<GateContractBase> */
    abstract protected function gateContract(): string;

    /** @return list<class-string<PipeBase>> */
    abstract protected function pipes(): array;

    private function gateBinding(): string
    {
        return static::class.'.gate';
    }

    private function pipeTag(): string
    {
        return static::class.'.pipes';
    }

    private function moduleBinding(): string
    {
        return static::class.'.module';
    }

    /** @param class-string<GateContractBase> $contract */
    private static function contract(Application $app, string $contract): GateContractBase
    {
        $resolved = $app->make($contract);
        if (! $resolved instanceof GateContractBase) {
            throw new LogicException("Gate contract binding [{$contract}] is invalid.");
        }

        return $resolved;
    }

    /** @return list<PipeBase> */
    private static function resolvedPipes(Application $app, string $tag): array
    {
        $pipes = [];
        foreach ($app->tagged($tag) as $pipe) {
            if (! $pipe instanceof PipeBase) {
                throw new LogicException("Pipeline tag [{$tag}] contains an invalid value.");
            }
            $pipes[] = $pipe;
        }

        return $pipes;
    }
}
