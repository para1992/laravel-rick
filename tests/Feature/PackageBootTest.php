<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Tests\TestCase;

final class PackageBootTest extends TestCase
{
    public function test_laravel_boots_the_package_and_resolves_the_main_entry_point(): void
    {
        $handler = $this->application()->make(Handler::class);

        self::assertInstanceOf(Handler::class, $handler);
    }

    public function test_every_configured_step_strategy_is_container_resolvable(): void
    {
        $registry = $this->application()->make(StepStrategyRegistry::class);
        $types = [
            'resolve',
            'raw_prompt',
            'define_dod',
            'context',
            'generate',
            'unfold',
            'judge',
            'edit',
            'output_glue',
            'operation',
            'quality_gate',
            'grounded_verify',
            'parallel',
            'map',
            'join',
            'branch',
            'wait_for_input',
        ];

        foreach ($types as $type) {
            $strategy = $registry->for(StepType::fromString($type));

            self::assertTrue($strategy->supports(StepType::fromString($type)), $type);
        }
    }

    public function test_operational_commands_are_registered(): void
    {
        $this->artisanCommand('rick:recipes')
            ->expectsOutput('rick.long_form')
            ->expectsOutput('rick.multi_perspective')
            ->expectsOutput('rick.refactoring_plan')
            ->assertSuccessful();
        $this->artisanCommand('rick:recover')->assertSuccessful();
    }
}
