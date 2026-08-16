<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Compilation\Support\Builder;

use LogicException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Support\Builder\ParallelBuilder;
use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\Step\JudgeStep;
use Rick\Laravel\Domain\Workflow\Step\ParallelStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\UnfoldStep;

final class WorkflowBuilderTest extends TestCase
{
    public function test_named_version_and_budget_build_the_complete_definition(): void
    {
        $definition = WorkflowBuilder::named('  article  ')
            ->version(' 2.1.0 ')
            ->budget(
                maxInputTokens: 100,
                maxOutputTokens: 200,
                maxTotalTokens: 250,
                maxCostUsd: '1.25',
                maxLatencyMilliseconds: 3_000,
                maxDurationMilliseconds: 10_000,
                defaultOutputReservationTokens: 64,
                requireCompleteMetrics: true,
                requireKnownPricing: false,
            )
            ->build();

        self::assertSame('article', $definition->name);
        self::assertSame('2.1.0', $definition->version);
        $budget = $definition->resourceBudget;
        self::assertNotNull($budget);
        self::assertSame(100, $budget->maxInputTokens);
        self::assertSame(200, $budget->maxOutputTokens);
        self::assertSame(250, $budget->maxTotalTokens);
        self::assertSame('1.25', $budget->maxCost?->toUsdDecimal());
        self::assertSame(3_000, $budget->maxLatencyMilliseconds);
        self::assertSame(10_000, $budget->maxDurationMilliseconds);
        self::assertSame(64, $budget->defaultOutputReservationTokens);
        self::assertTrue($budget->requireCompleteMetrics);
        self::assertFalse($budget->requireKnownPricing);
    }

    public function test_semantic_generation_and_manual_judge_helpers_preserve_step_order(): void
    {
        $steps = WorkflowBuilder::named('semantic')
            ->angle()
            ->manualJudge()
            ->plan(2)
            ->manualJudge()
            ->draft(4)
            ->build()
            ->steps;

        self::assertCount(5, $steps);
        self::assertInstanceOf(GenerateStep::class, $steps[0]);
        self::assertSame('angle', $steps[0]->artifact->toString());
        self::assertSame(3, $steps[0]->candidateCount);
        self::assertInstanceOf(JudgeStep::class, $steps[1]);
        self::assertInstanceOf(GenerateStep::class, $steps[2]);
        self::assertSame('plan', $steps[2]->artifact->toString());
        self::assertSame(2, $steps[2]->candidateCount);
        self::assertInstanceOf(JudgeStep::class, $steps[3]);
        self::assertInstanceOf(GenerateStep::class, $steps[4]);
        self::assertSame('draft', $steps[4]->artifact->toString());
        self::assertSame(4, $steps[4]->candidateCount);
        self::assertSame('001_generate', $steps[0]->id()->toString());
        self::assertSame('005_generate', $steps[4]->id()->toString());
    }

    public function test_automatic_and_manual_judge_helpers_preserve_their_modes(): void
    {
        $steps = WorkflowBuilder::named('judge-modes')
            ->draft(2)
            ->judge('quality')
            ->plan(2)
            ->manualJudge()
            ->build()
            ->steps;

        self::assertInstanceOf(JudgeStep::class, $steps[1]);
        self::assertTrue($steps[1]->automatic);
        self::assertSame('quality', $steps[1]->modelPolicyId);
        self::assertInstanceOf(JudgeStep::class, $steps[3]);
        self::assertFalse($steps[3]->automatic);
    }

    public function test_unfold_manual_judge_helpers_enable_per_unit_review(): void
    {
        $steps = WorkflowBuilder::named('unfold')
            ->unfoldManualJudge('plan', 'section', candidates: 2, maxUnits: 12, modelPolicy: 'writing')
            ->unfoldManualJudge('section', 'paragraph')
            ->build()
            ->steps;

        self::assertInstanceOf(UnfoldStep::class, $steps[0]);
        self::assertTrue($steps[0]->judge);
        self::assertSame(2, $steps[0]->candidateCount);
        self::assertSame(12, $steps[0]->maxUnits);
        self::assertSame('writing', $steps[0]->modelPolicyId);
        self::assertInstanceOf(UnfoldStep::class, $steps[1]);
        self::assertTrue($steps[1]->judge);
        self::assertSame(3, $steps[1]->candidateCount);
    }

    public function test_grounded_verification_exposes_operation_versions(): void
    {
        $step = WorkflowBuilder::named('grounded')
            ->groundedVerify(
                artifact: 'draft',
                evidence: ['source'],
                repairOperation: 'repair.grounded',
                maxRepairs: 2,
                output: 'verified',
                minimumQuoteCharacters: 20,
                verificationOperation: 'verify.grounded',
                verificationOperationVersion: '2',
                repairOperationVersion: '3',
            )
            ->build()
            ->steps[0];

        self::assertInstanceOf(GroundedVerifyStep::class, $step);
        self::assertSame('verify.grounded', $step->verificationOperationId);
        self::assertSame('2', $step->verificationOperationVersion);
        self::assertSame('repair.grounded', $step->repairOperationId);
        self::assertSame('3', $step->repairOperationVersion);
        self::assertSame(2, $step->maxRepairs);
        self::assertSame('verified', $step->outputKey);
        self::assertSame(20, $step->minimumQuoteCharacters);
    }

    public function test_parallel_callback_builds_operation_calls(): void
    {
        $step = WorkflowBuilder::named('parallel')
            ->parallel(static fn (ParallelBuilder $parallel): ParallelBuilder => $parallel
                ->operation('research', 'rick.research', 'research.output', ['brief'])
                ->operation(
                    'outline',
                    'rick.outline',
                    'outline.output',
                    ['brief'],
                    ['depth' => 3],
                    '2',
                ))
            ->build()
            ->steps[0];

        self::assertInstanceOf(ParallelStep::class, $step);
        self::assertCount(2, $step->calls);
        self::assertSame('research', $step->calls[0]->id);
        self::assertSame('rick.outline', $step->calls[1]->operationId);
        self::assertSame('2', $step->calls[1]->operationVersion);
        self::assertSame(['brief'], $step->calls[1]->inputKeys);
        self::assertSame(['depth' => 3], $step->calls[1]->parameters);
    }

    public function test_parallel_callback_rejects_an_empty_group(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires at least one operation');

        WorkflowBuilder::named('empty')
            ->parallel(static fn (ParallelBuilder $parallel): ParallelBuilder => $parallel);
    }

    public function test_raw_prompt_builds_a_single_terminal_step_without_wrappers(): void
    {
        $definition = WorkflowBuilder::named('measure')
            ->budget(maxCostUsd: '0.10')
            ->rawPrompt('  Preserve this prompt exactly.  ', 'quality')
            ->build();

        self::assertCount(1, $definition->steps);
        self::assertInstanceOf(RawPromptStep::class, $definition->steps[0]);
        self::assertSame('001_raw_prompt', $definition->steps[0]->id()->toString());
        self::assertSame('raw_prompt', $definition->steps[0]->type()->toString());
        self::assertSame('  Preserve this prompt exactly.  ', $definition->steps[0]->prompt);
        self::assertSame('quality', $definition->steps[0]->modelPolicyId);
    }
}
