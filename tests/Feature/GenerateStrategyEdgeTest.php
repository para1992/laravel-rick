<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use LogicException;
use Rick\Laravel\Application\Execution\Strategy\GenerateStrategy;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionMode;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Tests\TestCase;

final class GenerateStrategyEdgeTest extends TestCase
{
    public function test_it_supports_only_generate_and_rejects_incompatible_steps(): void
    {
        $strategy = $this->strategy();
        $raw = new RawPromptStep(StepId::fromString('raw'), 'Prompt');

        self::assertTrue($strategy->supports(StepType::generate()));
        self::assertFalse($strategy->supports(StepType::judge()));

        try {
            $strategy->plan($raw, $this->snapshot());
            self::fail('An incompatible generate plan was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Generate strategy received an incompatible step.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Generate strategy received an incompatible step.');
        $strategy->reduce($raw, $this->snapshot(), [
            $this->outcome('incompatible', 0, 1, InvocationStatus::Failed, null),
        ]);
    }

    public function test_plan_builds_exact_independent_requests_and_all_required_policy(): void
    {
        $plan = $this->strategy()->plan($this->step(2), $this->snapshot());

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(2, $plan->requests);
        self::assertSame(InvocationCompletionMode::AllRequired, $plan->completionPolicy->mode);
        self::assertNull($plan->completionPolicy->minimumSuccessful);
        self::assertSame([0, 1], array_column(array_map(
            static fn ($request): array => $request->metadata,
            $plan->requests,
        ), 'candidate_index'));
        self::assertSame(ResponseContract::Candidate, $plan->requests[0]->responseContract);
        self::assertSame('generate_candidate', $plan->requests[0]->purpose);
        self::assertSame('creative', $plan->requests[0]->modelTier);
        self::assertSame('rick.step.generate', $plan->requests[0]->metadata['prompt_profile_id']);
        self::assertSame(
            "Task:\nWrite Zażółć/slash\n\nDefinition of done:\n{\n    \"criteria\": [\n        \"Exact\"\n    ]\n}"
                ."\n\nInput artifacts:\n{\"source\":\"Source Zażółć\\/slash\"}"
                ."\n\nProduce one draft candidate.\n\nCandidate number: 1",
            $plan->requests[0]->messages[1]->content,
        );
        self::assertStringEndsWith('Candidate number: 2', $plan->requests[1]->messages[1]->content);
    }

    public function test_plan_uses_an_exact_minimum_successful_policy(): void
    {
        $plan = $this->strategy()->plan($this->step(3, 2), $this->snapshot());

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(3, $plan->requests);
        self::assertSame(InvocationCompletionMode::MinimumSuccessful, $plan->completionPolicy->mode);
        self::assertSame(2, $plan->completionPolicy->minimumSuccessful);
        self::assertSame(2, $plan->completionPolicy->required(3));
    }

    public function test_reduce_uses_structured_content_then_text_and_preserves_all_provenance(): void
    {
        $result = $this->strategy(['candidate-a', 'candidate-b'])->reduce(
            $this->step(2),
            $this->snapshot(),
            [
                $this->outcome(
                    'invocation-second',
                    1,
                    3,
                    InvocationStatus::Succeeded,
                    new CompletionResponse('ignored', ['content' => 'Structured result']),
                ),
                $this->outcome('invocation-failed', 8, 1, InvocationStatus::Failed, null),
                $this->outcome(
                    'invocation-first',
                    0,
                    2,
                    InvocationStatus::Succeeded,
                    new CompletionResponse('Text fallback'),
                ),
            ],
        );

        self::assertFalse($result->continuesStep);
        self::assertSame([
            [
                'schema_version' => 1,
                'id' => 'candidate-a',
                'step_id' => 'generate',
                'artifact' => 'draft',
                'title' => 'Candidate 2',
                'summary' => '',
                'payload' => [],
                'content' => 'Structured result',
                'seed' => [
                    'random_string' => 'invocation-second',
                    'interpretation' => 'independent candidate generation',
                ],
                'metadata' => [
                    'output_key' => 'draft-output',
                    'invocation_id' => 'invocation-second',
                    'original_index' => 1,
                    'candidate_number' => 2,
                    'attempts' => 3,
                ],
            ],
            [
                'schema_version' => 1,
                'id' => 'candidate-b',
                'step_id' => 'generate',
                'artifact' => 'draft',
                'title' => 'Candidate 1',
                'summary' => '',
                'payload' => [],
                'content' => 'Text fallback',
                'seed' => [
                    'random_string' => 'invocation-first',
                    'interpretation' => 'independent candidate generation',
                ],
                'metadata' => [
                    'output_key' => 'draft-output',
                    'invocation_id' => 'invocation-first',
                    'original_index' => 0,
                    'candidate_number' => 1,
                    'attempts' => 2,
                ],
            ],
        ], array_map(static fn (Candidate $candidate): array => $candidate->toArray(), $result->candidates));
    }

    public function test_reduce_rejects_a_non_string_candidate_content(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Generated candidate field [content] must be a string.');

        $this->strategy()->reduce(
            $this->step(1),
            $this->snapshot(),
            [$this->outcome(
                'invocation',
                0,
                1,
                InvocationStatus::Succeeded,
                new CompletionResponse(structured: ['content' => ['not-a-string']]),
            )],
        );
    }

    /** @param list<string> $ids */
    private function strategy(array $ids = ['candidate']): GenerateStrategy
    {
        $generator = new class($ids) implements IdGeneratorBase
        {
            /** @param list<string> $ids */
            public function __construct(private array $ids) {}

            public function generate(): string
            {
                return array_shift($this->ids) ?? 'candidate-fallback';
            }
        };

        return new GenerateStrategy(
            $generator,
            $this->application()->make(CompletionRequestFactory::class),
        );
    }

    private function step(int $count, ?int $minimumSuccessful = null): GenerateStep
    {
        return new GenerateStep(
            StepId::fromString('generate'),
            ArtifactType::fromString('draft'),
            $count,
            'draft-output',
            ['source'],
            'creative',
            $minimumSuccessful,
        );
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('generate-run'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Write Zażółć/slash',
            DefinitionOfDone::structured(['criteria' => ['Exact']]),
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            0,
            10,
            [
                'source' => new Artifact(
                    'source',
                    ArtifactType::fromString('text'),
                    'Source Zażółć/slash',
                ),
            ],
        );
    }

    private function outcome(
        string $id,
        int $index,
        int $attempts,
        InvocationStatus $status,
        ?CompletionResponse $response,
    ): InvocationOutcome {
        return new InvocationOutcome(
            InvocationId::fromString($id),
            $index,
            $attempts,
            $status,
            $response,
            $status === InvocationStatus::Failed ? 'failed' : null,
            $status === InvocationStatus::Failed ? 'Failed' : null,
        );
    }
}
