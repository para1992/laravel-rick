<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Compilation\Pipe;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Interface\CompilerBase;
use Rick\Laravel\Application\Compilation\Interface\DefinitionBase;
use Rick\Laravel\Application\Compilation\Interface\PlanBase;
use Rick\Laravel\Application\Compilation\Pipe\CompilePipe;
use Rick\Laravel\Domain\ValueObject\Parcel;

final class CompilePipeTest extends TestCase
{
    public function test_it_compiles_the_definition_and_puts_the_plan_into_the_parcel(): void
    {
        $definition = new class implements DefinitionBase {};
        $plan = new class implements PlanBase {};
        $compiler = new class($definition, $plan) implements CompilerBase
        {
            public function __construct(
                private readonly DefinitionBase $expectedDefinition,
                private readonly PlanBase $plan,
            ) {}

            public function compile(DefinitionBase $definition): PlanBase
            {
                TestCase::assertSame($this->expectedDefinition, $definition);

                return $this->plan;
            }
        };

        $result = (new CompilePipe($compiler))->process(
            Parcel::fromArray([$definition]),
            static fn (Parcel $parcel): Parcel => $parcel,
        );

        self::assertSame($definition, $result->get(DefinitionBase::class));
        self::assertSame($plan, $result->get(PlanBase::class));
    }
}
