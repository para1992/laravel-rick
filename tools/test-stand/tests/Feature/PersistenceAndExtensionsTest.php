<?php

declare(strict_types=1);

namespace Rick\Stand\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkflowStepCodec;
use Rick\Stand\Tests\TestCase;
use UnexpectedValueException;

final class PersistenceAndExtensionsTest extends TestCase
{
    public function test_versioned_codec_round_trip_and_future_schema_fail_closed(): void
    {
        $codec = $this->application()->make(WorkflowStepCodec::class);
        $step = new ResolveStep(StepId::fromString('001_resolve'), 'Task', DefinitionOfDone::fromString('Done'));
        $payload = $codec->encode($step);
        self::assertEquals($step, $codec->decode($payload));
        $payload['schema_version'] = 999;
        $this->expectException(UnexpectedValueException::class);
        $codec->decode($payload);
    }

    public function test_recipes_operations_quality_and_commands_are_registered(): void
    {
        $recipes = $this->application()->make(RecipeRegistry::class);
        self::assertSame([
            'rick.humanizer',
            'rick.long_form',
            'rick.multi_perspective',
            'rick.refactoring_plan',
        ], $recipes->ids());
        $commands = array_keys(Artisan::all());
        foreach (['rick:diagnose', 'rick:run', 'rick:recipes', 'rick:recover', 'rick:outbox:relay'] as $command) {
            self::assertContains($command, $commands);
        }
        $config = $this->application()->make('config')->get('rick');
        self::assertCount(18, $config['execution']['strategies']);
        self::assertSame([
            'rick.humanizer.draft',
            'rick.humanizer.audit',
            'rick.humanizer.taste_audit',
            'rick.humanizer.revise',
            'rick.humanizer.grounding_repair',
            'rick.text',
            'rick.repair.text',
            'rick.verify.grounded',
        ], array_keys($config['llm']['operations']));
        self::assertArrayHasKey('non_empty', $config['quality']['rule_sets']);
    }
}
