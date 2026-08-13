<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Registry;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class StepStrategyRegistry
{
    /** @param array<string, class-string<StepStrategyBase>> $strategies */
    public function __construct(
        private Container $container,
        private array $strategies,
    ) {}

    public function for(StepType $type): StepStrategyBase
    {
        $class = $this->strategies[$type->toString()] ?? null;
        if ($class === null) {
            throw new LogicException(sprintf(
                'No strategy is configured for step type [%s].',
                $type->toString(),
            ));
        }

        $strategy = $this->container->make($class);
        if (! $strategy instanceof StepStrategyBase || ! $strategy->supports($type)) {
            throw new LogicException(sprintf(
                'Configured strategy [%s] does not support step type [%s].',
                $class,
                $type->toString(),
            ));
        }

        return $strategy;
    }
}
