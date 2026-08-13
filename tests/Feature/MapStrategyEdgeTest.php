<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use LogicException;
use Rick\Laravel\Application\Execution\Strategy\MapStrategy;
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
use Rick\Laravel\Domain\Workflow\Step\MapStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Tests\TestCase;

final class MapStrategyEdgeTest extends TestCase
{
    public function test_it_supports_only_map_and_rejects_incompatible_plan_and_reduce_steps(): void
    {
        $strategy = $this->strategy();
        $raw = new RawPromptStep(StepId::fromString('raw'), 'Prompt');

        self::assertTrue($strategy->supports(StepType::map()));
        self::assertFalse($strategy->supports(StepType::parallel()));

        try {
            $strategy->plan($raw, $this->snapshot([]));
            self::fail('An incompatible plan step was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Map strategy received an incompatible step.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Map strategy received an incompatible step.');
        $strategy->reduce($raw, $this->snapshot([]), [
            $this->outcome(0, InvocationStatus::Failed, null),
        ]);
    }

    public function test_plan_reads_a_nested_path_reindexes_limits_and_builds_exact_requests(): void
    {
        $step = $this->step(sourcePath: '.nested..items.', maxItems: 2);
        $snapshot = $this->snapshot([
            'nested' => [
                'items' => [
                    'third' => ['name' => 'Zażółć/slash'],
                    'first' => 7,
                    'ignored' => 'third',
                ],
            ],
        ]);

        $plan = $this->strategy()->plan($step, $snapshot);

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(2, $plan->requests);
        self::assertSame([0, 1], array_column(array_map(
            static fn ($request): array => $request->metadata,
            $plan->requests,
        ), 'map_index'));
        self::assertSame(['rick.operation', 'rick.operation'], array_column(array_map(
            static fn ($request): array => ['purpose' => $request->purpose],
            $plan->requests,
        ), 'purpose'));
        self::assertSame(
            "Execute rick.operation for map item.\n"
                .'{"item":{"name":"Zażółć\/slash"},"index":0,"parameters":{"tone":"clear"}}',
            $plan->requests[0]->messages[1]->content,
        );
        self::assertSame(
            "Execute rick.operation for map item.\n"
                .'{"item":7,"index":1,"parameters":{"tone":"clear"}}',
            $plan->requests[1]->messages[1]->content,
        );
        self::assertSame('rick.step.map', $plan->requests[0]->metadata['prompt_profile_id']);
        self::assertSame('medium', $plan->requests[0]->modelTier);
    }

    public function test_plan_rejects_missing_scalar_and_empty_sources(): void
    {
        $strategy = $this->strategy();

        foreach ([
            ['payload' => ['nested' => 'scalar'], 'path' => 'nested.items', 'message' => 'Map source path must resolve to an array.'],
            ['payload' => ['nested' => []], 'path' => 'nested.items', 'message' => 'Map source path must resolve to an array.'],
            ['payload' => ['nested' => ['items' => []]], 'path' => 'nested.items', 'message' => 'Map source contains no items.'],
        ] as $case) {
            try {
                $strategy->plan(
                    $this->step(sourcePath: $case['path']),
                    $this->snapshot($case['payload']),
                );
                self::fail('An invalid map source was accepted.');
            } catch (LogicException $exception) {
                self::assertSame($case['message'], $exception->getMessage());
            }
        }
    }

    public function test_reduce_keeps_successful_response_order_and_exact_collection_payload(): void
    {
        $outcome = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot(['items' => ['a', 'b']]),
            [
                $this->outcome(2, InvocationStatus::Failed, null),
                $this->outcome(1, InvocationStatus::Succeeded, new CompletionResponse('Zażółć/slash')),
                $this->outcome(0, InvocationStatus::Succeeded, new CompletionResponse('second')),
            ],
        );

        self::assertFalse($outcome->continuesStep);
        self::assertCount(1, $outcome->artifacts);
        self::assertSame([
            'schema_version' => 1,
            'key' => 'mapped',
            'type' => 'collection',
            'content' => '["Zażółć\/slash","second"]',
            'payload' => ['items' => ['Zażółć/slash', 'second']],
            'metadata' => [],
            'version' => 1,
        ], $outcome->artifacts[0]->toArray());
    }

    private function strategy(): MapStrategy
    {
        return new MapStrategy($this->application()->make(CompletionRequestFactory::class));
    }

    private function step(string $sourcePath = 'items', int $maxItems = 50): MapStep
    {
        return new MapStep(
            StepId::fromString('map'),
            'source',
            $sourcePath,
            'rick.operation',
            '1.2.3',
            'mapped',
            ['tone' => 'clear'],
            $maxItems,
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function snapshot(array $payload): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('map-run'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Map source',
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
            ['source' => new Artifact('source', ArtifactType::fromString('collection'), 'source', $payload)],
        );
    }

    private function outcome(
        int $index,
        InvocationStatus $status,
        ?CompletionResponse $response,
    ): InvocationOutcome {
        return new InvocationOutcome(
            InvocationId::fromString('map-invocation-'.$index),
            $index,
            1,
            $status,
            $response,
            $status === InvocationStatus::Failed ? 'failed' : null,
            $status === InvocationStatus::Failed ? 'Failed' : null,
        );
    }
}
