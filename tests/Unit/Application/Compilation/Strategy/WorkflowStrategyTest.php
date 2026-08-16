<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Compilation\Strategy;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Exception\WorkflowValidationException;
use Rick\Laravel\Application\Compilation\Strategy\WorkflowStrategy;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowDefinition;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowPlan;
use Rick\Laravel\Domain\Workflow\Step\AgentStep;
use Rick\Laravel\Domain\Workflow\Step\ApplicationStep;
use Rick\Laravel\Domain\Workflow\Step\DefineDodStep;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition as DomainWorkflowDefinition;
use Rick\Laravel\Infrastructure\Schema\JsonSchemaValidator;

final class WorkflowStrategyTest extends TestCase
{
    public function test_it_matches_core_automatic_dod_and_terminal_output_compilation(): void
    {
        $plan = $this->compile(new DomainWorkflowDefinition('auto-dod', '1.0.0', [
            new ResolveStep(
                StepId::fromString('001_resolve'),
                'Write',
                DefinitionOfDone::automatic(),
            ),
            new GenerateStep(
                StepId::fromString('002_generate'),
                ArtifactType::fromString('draft'),
                1,
            ),
        ]));

        self::assertSame(4, $plan->workflow->count());
        self::assertInstanceOf(ResolveStep::class, $plan->workflow->steps[0]);
        self::assertInstanceOf(DefineDodStep::class, $plan->workflow->steps[1]);
        self::assertInstanceOf(GenerateStep::class, $plan->workflow->steps[2]);
        self::assertInstanceOf(OutputGlueStep::class, $plan->workflow->steps[3]);
    }

    public function test_it_compiles_an_application_first_workflow_without_resolve(): void
    {
        $plan = $this->compile(new DomainWorkflowDefinition('claim-decision', '1.0.0', [
            new ApplicationStep(StepId::fromString('load-claim'), 'App\\LoadClaim'),
            new AgentStep(StepId::fromString('facts'), 'App\\ExtractFacts'),
            new OutputGlueStep(StepId::fromString('output'), 'decision'),
        ]));

        self::assertCount(3, $plan->workflow->steps);
        self::assertInstanceOf(ApplicationStep::class, $plan->workflow->steps[0]);
        self::assertInstanceOf(AgentStep::class, $plan->workflow->steps[1]);
        self::assertInstanceOf(OutputGlueStep::class, $plan->workflow->steps[2]);
    }

    public function test_it_compiles_a_workflow_without_resolve_as_the_first_step(): void
    {
        $plan = $this->compile(new DomainWorkflowDefinition('no-resolve', '1.0.0', [
            new GenerateStep(
                StepId::fromString('001_generate'),
                ArtifactType::fromString('draft'),
                1,
            ),
        ]));

        self::assertCount(2, $plan->workflow->steps);
        self::assertInstanceOf(GenerateStep::class, $plan->workflow->steps[0]);
        self::assertInstanceOf(OutputGlueStep::class, $plan->workflow->steps[1]);
    }

    public function test_it_rejects_resolve_after_the_first_step(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->expectExceptionMessage('RESOLVE may only appear as the first step.');

        $this->compile(new DomainWorkflowDefinition('misplaced-resolve', '1.0.0', [
            new ApplicationStep(StepId::fromString('load-claim'), 'App\\LoadClaim'),
            new ResolveStep(StepId::fromString('resolve'), 'Write', DefinitionOfDone::fromString('Done')),
        ]));
    }

    public function test_it_rejects_duplicate_step_ids(): void
    {
        $id = StepId::fromString('duplicate');

        $this->expectException(WorkflowValidationException::class);
        $this->expectExceptionMessage('Duplicate step id [duplicate].');

        $this->compile(new DomainWorkflowDefinition('duplicates', '1.0.0', [
            new ResolveStep($id, 'Write', DefinitionOfDone::fromString('Done')),
            new GenerateStep($id, ArtifactType::fromString('draft'), 1),
        ]));
    }

    public function test_it_compiles_a_single_raw_prompt_without_resolve_or_output_glue(): void
    {
        $plan = $this->compile(new DomainWorkflowDefinition('raw', '1.0.0', [
            new RawPromptStep(
                StepId::fromString('001_raw_prompt'),
                'Measure this exact prompt.',
                'default',
            ),
        ]));

        self::assertCount(1, $plan->workflow->steps);
        self::assertInstanceOf(RawPromptStep::class, $plan->workflow->steps[0]);
    }

    public function test_it_rejects_raw_prompt_inside_a_multi_step_workflow(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->expectExceptionMessage('RAW_PROMPT must be the only workflow step.');

        $this->compile(new DomainWorkflowDefinition('invalid-raw', '1.0.0', [
            new ResolveStep(
                StepId::fromString('001_resolve'),
                'Write',
                DefinitionOfDone::fromString('Done'),
            ),
            new RawPromptStep(
                StepId::fromString('002_raw_prompt'),
                'Do not wrap this.',
            ),
        ]));
    }

    private function compile(DomainWorkflowDefinition $definition): WorkflowPlan
    {
        $plan = (new WorkflowStrategy(new JsonSchemaValidator))->compile(new WorkflowDefinition($definition));

        self::assertInstanceOf(WorkflowPlan::class, $plan);

        return $plan;
    }
}
