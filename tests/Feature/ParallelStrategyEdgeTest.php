<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use LogicException;
use Rick\Laravel\Application\Execution\Strategy\ParallelStrategy;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\Step\ParallelStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Tests\TestCase;

final class ParallelStrategyEdgeTest extends TestCase
{
    public function test_it_supports_only_parallel_and_rejects_incompatible_steps(): void
    {
        $strategy = $this->strategy();
        $raw = new RawPromptStep(StepId::fromString('raw'), 'Prompt');

        self::assertTrue($strategy->supports(StepType::parallel()));
        self::assertFalse($strategy->supports(StepType::map()));

        try {
            $strategy->plan($raw, $this->snapshot());
            self::fail('An incompatible parallel plan was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Parallel strategy received an incompatible step.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Parallel strategy received an incompatible step.');
        $strategy->reduce($raw, $this->snapshot(), [
            $this->outcome(0, InvocationStatus::Failed, null),
        ]);
    }

    public function test_plan_builds_one_exact_request_per_call_with_declared_inputs(): void
    {
        $plan = $this->strategy()->plan($this->step(), $this->snapshot());

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(2, $plan->requests);
        self::assertSame('operation.first', $plan->requests[0]->purpose);
        self::assertSame('operation.second', $plan->requests[1]->purpose);
        self::assertSame('first-output', $plan->requests[0]->metadata['output_key']);
        self::assertSame('second-output', $plan->requests[1]->metadata['output_key']);
        self::assertSame('rick.step.parallel', $plan->requests[0]->metadata['prompt_profile_id']);
        self::assertSame(
            "Execute operation.first.\n"
                .'{"inputs":{"source":{"schema_version":1,"key":"source","type":"text","content":"Zażółć\/slash","payload":{"kind":"input"},"metadata":{"origin":"test"},"version":2}},"parameters":{"tone":"clear"}}',
            $plan->requests[0]->messages[1]->content,
        );
        self::assertSame(
            "Execute operation.second.\n"
                .'{"inputs":{"source":{"schema_version":1,"key":"source","type":"text","content":"Zażółć\/slash","payload":{"kind":"input"},"metadata":{"origin":"test"},"version":2},"context":{"schema_version":1,"key":"context","type":"text","content":"Context","payload":[],"metadata":[],"version":1}},"parameters":{"mode":"strict"}}',
            $plan->requests[1]->messages[1]->content,
        );
    }

    public function test_reduce_maps_original_indices_and_preserves_successful_outcome_order(): void
    {
        $result = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot(),
            [
                $this->outcome(1, InvocationStatus::Succeeded, new CompletionResponse('Second result')),
                $this->outcome(7, InvocationStatus::Failed, null),
                $this->outcome(0, InvocationStatus::Succeeded, new CompletionResponse('First result')),
            ],
        );

        self::assertFalse($result->continuesStep);
        self::assertSame([
            [
                'schema_version' => 1,
                'key' => 'second-output',
                'type' => 'operation',
                'content' => 'Second result',
                'payload' => [],
                'metadata' => [],
                'version' => 1,
            ],
            [
                'schema_version' => 1,
                'key' => 'first-output',
                'type' => 'operation',
                'content' => 'First result',
                'payload' => [],
                'metadata' => [],
                'version' => 1,
            ],
        ], array_map(static fn (Artifact $artifact): array => $artifact->toArray(), $result->artifacts));
    }

    public function test_reduce_rejects_a_successful_outcome_without_a_matching_call(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Parallel invocation index has no matching operation call.');

        $this->strategy()->reduce(
            $this->step(),
            $this->snapshot(),
            [$this->outcome(9, InvocationStatus::Succeeded, new CompletionResponse('orphan'))],
        );
    }

    private function strategy(): ParallelStrategy
    {
        return new ParallelStrategy($this->application()->make(CompletionRequestFactory::class));
    }

    private function step(): ParallelStep
    {
        return new ParallelStep(StepId::fromString('parallel'), [
            new OperationCall(
                'first',
                'operation.first',
                '1.0.0',
                ['source'],
                'first-output',
                ['tone' => 'clear'],
            ),
            new OperationCall(
                'second',
                'operation.second',
                null,
                ['source', 'context'],
                'second-output',
                ['mode' => 'strict'],
            ),
        ]);
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('parallel-run'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Run operations',
            DefinitionOfDone::automatic(),
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
                    'Zażółć/slash',
                    ['kind' => 'input'],
                    ['origin' => 'test'],
                    2,
                ),
                'context' => new Artifact('context', ArtifactType::fromString('text'), 'Context'),
            ],
        );
    }

    private function outcome(
        int $index,
        InvocationStatus $status,
        ?CompletionResponse $response,
    ): InvocationOutcome {
        return new InvocationOutcome(
            InvocationId::fromString('parallel-invocation-'.$index),
            $index,
            1,
            $status,
            $response,
            $status === InvocationStatus::Failed ? 'failed' : null,
            $status === InvocationStatus::Failed ? 'Failed' : null,
        );
    }
}
