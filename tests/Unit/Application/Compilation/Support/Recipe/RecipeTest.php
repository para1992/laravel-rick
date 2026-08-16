<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Compilation\Support\Recipe;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Support\Recipe\HumanizerRecipe;
use Rick\Laravel\Application\Compilation\Support\Recipe\LongFormRecipe;
use Rick\Laravel\Application\Compilation\Support\Recipe\MultiPerspectiveAnalysisRecipe;
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeConfiguration;
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;
use Rick\Laravel\Application\Compilation\Support\Recipe\RefactoringPlanRecipe;
use Rick\Laravel\Domain\Workflow\Step\ContextStep;
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\Step\LlmOperationStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\QualityGateStep;
use Rick\Laravel\Domain\Workflow\Step\WaitForInputStep;

final class RecipeTest extends TestCase
{
    public function test_configuration_returns_trimmed_values_and_defaults(): void
    {
        $configuration = new RecipeConfiguration([
            'name' => '  custom  ',
            'count' => 3,
            'enabled' => true,
        ]);

        self::assertSame('custom', $configuration->string('name'));
        self::assertSame('fallback', $configuration->string('missing', 'fallback'));
        self::assertSame(3, $configuration->integer('count', 1));
        self::assertSame(7, $configuration->integer('missing_count', 7));
        self::assertTrue($configuration->boolean('enabled'));
        self::assertFalse($configuration->boolean('missing_enabled'));
    }

    #[DataProvider('invalidConfiguration')]
    public function test_configuration_rejects_invalid_values(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'missing string' => [static fn () => (new RecipeConfiguration([]))->string('required')];
        yield 'blank string' => [static fn () => (new RecipeConfiguration(['value' => ' ']))->string('value')];
        yield 'non-string' => [static fn () => (new RecipeConfiguration(['value' => 1]))->string('value')];
        yield 'small integer' => [static fn () => (new RecipeConfiguration(['value' => 1]))->integer('value', 2, 2)];
        yield 'non-integer' => [static fn () => (new RecipeConfiguration(['value' => '2']))->integer('value', 2)];
        yield 'non-boolean' => [static fn () => (new RecipeConfiguration(['value' => 1]))->boolean('value')];
    }

    public function test_registry_builds_every_builtin_recipe_and_sorts_ids(): void
    {
        $longForm = new LongFormRecipe;
        $humanizer = new HumanizerRecipe;
        $multiPerspective = new MultiPerspectiveAnalysisRecipe;
        $refactoring = new RefactoringPlanRecipe;
        $registry = new RecipeRegistry([$refactoring, $longForm, $humanizer]);
        $registry->register($multiPerspective);

        self::assertSame([
            'rick.humanizer',
            'rick.long_form',
            'rick.multi_perspective',
            'rick.refactoring_plan',
        ], $registry->ids());
        self::assertSame($longForm, $registry->get('rick.long_form'));

        self::assertSame('1.0.0', $humanizer->version());
        self::assertNotSame('', $humanizer->description());
        $humanizerDefinition = $registry->build('rick.humanizer', [
            'source_key' => 'article',
            'use_voice_sample' => true,
            'voice_sample_key' => 'author_sample',
        ]);
        self::assertSame('rick-humanizer', $humanizerDefinition->name);
        self::assertSame([
            ContextStep::class,
            ContextStep::class,
            LlmOperationStep::class,
            LlmOperationStep::class,
            LlmOperationStep::class,
            LlmOperationStep::class,
            GroundedVerifyStep::class,
            QualityGateStep::class,
            OutputGlueStep::class,
        ], array_map(
            static fn (object $step): string => $step::class,
            array_slice($humanizerDefinition->steps, 1),
        ));

        self::assertSame('1.0.0', $longForm->version());
        self::assertNotSame('', $longForm->description());
        self::assertSame('long-form', $registry->build('rick.long_form', [
            'name' => 'long-form',
            'task' => 'Write it',
            'dod' => 'It is complete',
            'context_key' => 'brief',
            'unit_artifact' => 'chapter',
            'candidates_per_unit' => 2,
            'max_units' => 4,
        ])->name);

        self::assertSame('1.0.0', $multiPerspective->version());
        self::assertNotSame('', $multiPerspective->description());
        self::assertCount(5, $registry->build('rick.multi_perspective', [
            'task' => 'Analyse it',
            'dod' => 'Analysis is complete',
        ])->steps);

        self::assertSame('1.0.0', $refactoring->version());
        self::assertNotSame('', $refactoring->description());
        $definition = $registry->build('rick.refactoring_plan', [
            'task' => 'Refactor it',
            'dod' => 'The plan is safe',
            'require_approval' => true,
        ]);
        self::assertTrue((bool) array_filter(
            $definition->steps,
            static fn ($step): bool => $step instanceof WaitForInputStep,
        ));
    }

    public function test_registry_rejects_an_unknown_recipe(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('missing');

        (new RecipeRegistry)->build('missing');
    }
}
