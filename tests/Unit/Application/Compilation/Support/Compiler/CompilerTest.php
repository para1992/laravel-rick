<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Compilation\Support\Compiler;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Exception\StrategyAmbiguousException;
use Rick\Laravel\Application\Compilation\Exception\StrategyNotFoundException;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Application\Compilation\Interface\PlanBase;
use Rick\Laravel\Application\Compilation\Interface\StrategyBase;
use Rick\Laravel\Application\Compilation\Support\Compiler\Compiler;

final class CompilerTest extends TestCase
{
    public function test_it_uses_the_single_supporting_strategy(): void
    {
        $definition = new class implements DefinitionBase {};
        $plan = new class implements PlanBase {};
        $strategy = new class($definition, $plan) implements StrategyBase
        {
            public function __construct(
                private readonly DefinitionBase $supported,
                private readonly PlanBase $plan,
            ) {}

            public function supports(DefinitionBase $definition): bool
            {
                return $definition === $this->supported;
            }

            public function compile(DefinitionBase $definition): PlanBase
            {
                TestCase::assertSame($this->supported, $definition);

                return $this->plan;
            }
        };

        self::assertSame($plan, (new Compiler([$strategy]))->compile($definition));
    }

    public function test_it_rejects_a_definition_without_a_strategy(): void
    {
        $this->expectException(StrategyNotFoundException::class);

        (new Compiler([]))->compile(new class implements DefinitionBase {});
    }

    public function test_it_rejects_multiple_supporting_strategies(): void
    {
        $definition = new class implements DefinitionBase {};
        $strategy = new class implements StrategyBase
        {
            public function supports(DefinitionBase $definition): bool
            {
                return true;
            }

            public function compile(DefinitionBase $definition): PlanBase
            {
                return new class implements PlanBase {};
            }
        };

        $this->expectException(StrategyAmbiguousException::class);

        (new Compiler([$strategy, $strategy]))->compile($definition);
    }
}
