<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use InvalidArgumentException;
use LogicException;
use Rick\Laravel\Application\Execution\Exception\GroundedVerificationFailedException;
use Rick\Laravel\Application\Execution\Strategy\GroundedVerifyStrategy;
use Rick\Laravel\Application\Execution\Support\Grounding\ExactQuoteVerifier;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingSegmenter;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
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
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Tests\TestCase;

final class GroundedVerifyStrategyEdgeTest extends TestCase
{
    public function test_batch_size_and_strategy_type_boundaries_fail_closed(): void
    {
        $registry = $this->application()->make(LlmOperationRegistry::class);
        try {
            new GroundedVerifyStrategy($registry, new GroundingSegmenter, new ExactQuoteVerifier, 0);
            self::fail('A zero verification batch size was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Grounded verification batch size must be positive.', $error->getMessage());
        }

        $strategy = new GroundedVerifyStrategy($registry, new GroundingSegmenter, new ExactQuoteVerifier, 1);
        self::assertTrue($strategy->supports(StepType::groundedVerify()));
        self::assertFalse($strategy->supports(StepType::rawPrompt()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('received an incompatible step');
        $strategy->plan(new RawPromptStep(StepId::fromString('raw'), 'prompt'), $this->snapshot());
    }

    public function test_plan_batches_each_unit_and_failed_state_preserves_only_string_violations(): void
    {
        $strategy = $this->strategy(1);
        $step = $this->step();
        $plan = $strategy->plan($step, $this->snapshot(target: "First grounded sentence.\nSecond grounded sentence."));

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        self::assertCount(2, $plan->requests);
        self::assertSame([0, 1], array_column(array_map(
            static fn ($request): array => $request->metadata,
            $plan->requests,
        ), 'grounding_batch'));
        self::assertSame([['unit-00001'], ['unit-00002']], array_column(array_map(
            static fn ($request): array => $request->metadata,
            $plan->requests,
        ), 'grounding_unit_ids'));
        foreach ($plan->requests as $index => $request) {
            self::assertSame(2, $request->structuredResponseAttempts);
            $schema = $request->responseSchema;
            self::assertIsArray($schema);
            $properties = $schema['properties'] ?? null;
            self::assertIsArray($properties);
            $claims = $properties['claims'] ?? null;
            self::assertIsArray($claims);
            self::assertSame(1, $claims['minItems'] ?? null);
            self::assertSame(1, $claims['maxItems'] ?? null);
            $items = $claims['items'] ?? null;
            self::assertIsArray($items);
            $claimProperties = $items['properties'] ?? null;
            self::assertIsArray($claimProperties);
            $unitId = $claimProperties['unit_id'] ?? null;
            self::assertIsArray($unitId);
            self::assertSame(
                ['unit-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT)],
                $unitId['enum'] ?? null,
            );
            $evidence = $claimProperties['evidence'] ?? null;
            self::assertIsArray($evidence);
            $references = $evidence['items'] ?? null;
            self::assertIsArray($references);
            $referenceProperties = $references['properties'] ?? null;
            self::assertIsArray($referenceProperties);
            $artifactKey = $referenceProperties['artifact_key'] ?? null;
            self::assertIsArray($artifactKey);
            self::assertSame(
                ['evidence'],
                $artifactKey['enum'] ?? null,
            );
            $payload = json_decode(
                explode("\n\n", $request->messages[1]->content, 2)[1],
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($payload);
            self::assertSame($schema, $payload['output_schema'] ?? null);
            $inputs = $payload['inputs'] ?? null;
            self::assertIsArray($inputs);
            $target = $inputs['target'] ?? null;
            self::assertIsArray($target);
            self::assertSame(
                $index === 0 ? 'First grounded sentence.' : 'Second grounded sentence.',
                $target['content'] ?? null,
            );
            self::assertSame([], $target['payload'] ?? null);
            $metadata = $target['metadata'] ?? null;
            self::assertIsArray($metadata);
            self::assertTrue(($metadata['grounding_batch_view'] ?? null) === true);
        }

        try {
            $strategy->plan($step, $this->snapshot(state: [
                'phase' => 'failed',
                'violations' => [1, 'first violation', null, 'second violation'],
            ]));
            self::fail('A failed grounding state was planned again.');
        } catch (GroundedVerificationFailedException $error) {
            self::assertSame(['first violation', 'second violation'], $error->violations);
            self::assertSame(
                'Grounded verification failed: first violation; second violation',
                $error->getMessage(),
            );
        }
    }

    public function test_verification_defaults_malformed_optional_state_and_stops_at_repair_limit(): void
    {
        $step = $this->step(maxRepairs: 1);
        $response = new CompletionResponse(structured: [
            'passed' => false,
            'claims' => [[
                'unit_id' => 'unit-00001',
                'claim' => 'Unsupported',
                'source_quote' => 'Original target.',
                'verdict' => 'unsupported',
                'evidence' => [],
            ]],
        ]);
        $outcome = $this->strategy()->reduce(
            $step,
            $this->snapshot(state: [
                'phase' => 'verify',
                'repairs_used' => 1,
                'reports' => 'invalid',
            ]),
            [$this->outcome($response)],
        );

        self::assertTrue($outcome->continuesStep);
        self::assertIsArray($outcome->stepState);
        self::assertSame('failed', $outcome->stepState['phase'] ?? null);
        self::assertSame(1, $outcome->stepState['repairs_used'] ?? null);
        $reports = $outcome->stepState['reports'] ?? null;
        self::assertIsArray($reports);
        self::assertCount(1, $reports);
        $report = $reports[0] ?? null;
        self::assertIsArray($report);
        self::assertSame($report['violations'] ?? null, $outcome->stepState['violations'] ?? null);
        self::assertSame([
            'grounded_verification' => 'failed',
            'repairs_used' => 1,
            'verification_retries_used' => 0,
        ], $outcome->metadata);
        self::assertCount(1, $outcome->artifacts);
        self::assertSame('verified.verification', $outcome->artifacts[0]->key);
    }

    public function test_claim_verdicts_override_an_inconsistent_aggregate_pass_flag(): void
    {
        $response = new CompletionResponse(structured: [
            'passed' => false,
            'claims' => [[
                'unit_id' => 'unit-00001',
                'claim' => 'Original target is supported.',
                'source_quote' => 'Original target.',
                'verdict' => 'supported',
                'evidence' => [[
                    'artifact_key' => 'evidence',
                    'quote' => 'Original target is not supported.',
                ]],
            ]],
        ]);

        $outcome = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot(),
            [$this->outcome($response)],
        );

        self::assertFalse($outcome->continuesStep);
        $state = $outcome->stepState;
        self::assertIsArray($state);
        self::assertSame('passed', $state['phase'] ?? null);
        $reports = $state['reports'] ?? null;
        self::assertIsArray($reports);
        $report = $reports[0] ?? null;
        self::assertIsArray($report);
        self::assertTrue($report['model_passed'] ?? false);
        self::assertTrue($report['passed'] ?? false);
    }

    public function test_valid_unit_id_uses_the_deterministic_unit_as_source_quote(): void
    {
        $response = new CompletionResponse(structured: [
            'passed' => true,
            'claims' => [[
                'unit_id' => 'unit-00001',
                'claim' => 'Original target is supported.',
                'source_quote' => 'Model copied the wrong source.',
                'verdict' => 'supported',
                'evidence' => [[
                    'artifact_key' => 'evidence',
                    'quote' => 'Original target is not supported.',
                ]],
            ]],
        ]);

        $outcome = $this->strategy()->reduce(
            $this->step(),
            $this->snapshot(),
            [$this->outcome($response)],
        );

        self::assertFalse($outcome->continuesStep);
        $report = $outcome->artifacts[0]->payload;
        self::assertTrue($report['passed'] ?? false);
        $claims = $report['claims'] ?? null;
        self::assertIsArray($claims);
        $claim = $claims[0] ?? null;
        self::assertIsArray($claim);
        self::assertSame('Original target.', $claim['source_quote'] ?? null);
        self::assertSame([], $report['protocol_violations'] ?? null);
    }

    public function test_protocol_violation_retries_verifier_without_repairing_the_artifact(): void
    {
        $response = new CompletionResponse(structured: [
            'passed' => true,
            'claims' => [[
                'unit_id' => 'retell_01',
                'claim' => 'Wrong protocol identifier',
                'source_quote' => 'Original target.',
                'verdict' => 'supported',
                'evidence' => [[
                    'artifact_key' => 'evidence',
                    'quote' => 'Original target is not supported.',
                ]],
            ]],
        ]);

        $outcome = $this->strategy()->reduce(
            $this->step(maxRepairs: 2),
            $this->snapshot(),
            [$this->outcome($response)],
        );

        self::assertTrue($outcome->continuesStep);
        self::assertSame('verify', $outcome->stepState['phase'] ?? null);
        self::assertSame(0, $outcome->stepState['repairs_used'] ?? null);
        self::assertSame(1, $outcome->stepState['verification_retries_used'] ?? null);
        self::assertNotEmpty($outcome->stepState['protocol_violations'] ?? []);
        self::assertSame('Original target.', $this->snapshot()->artifact('target')->content);
        self::assertSame([
            'grounded_verification' => 'verify',
            'repairs_used' => 0,
            'verification_retries_used' => 1,
        ], $outcome->metadata);
        self::assertCount(1, $outcome->artifacts);
        self::assertSame('verified.verification', $outcome->artifacts[0]->key);
    }

    public function test_exhausted_quote_protocol_retries_may_repair_but_missing_units_never_do(): void
    {
        $strategy = $this->strategy();
        $state = [
            'phase' => 'verify',
            'repairs_used' => 0,
            'verification_retries_used' => 2,
        ];
        $quoteFailure = new CompletionResponse(structured: [
            'passed' => true,
            'claims' => [[
                'unit_id' => 'unit-00001',
                'claim' => 'Model invented an evidence quote.',
                'source_quote' => 'Original target.',
                'verdict' => 'supported',
                'evidence' => [[
                    'artifact_key' => 'evidence',
                    'quote' => 'Invented evidence quote.',
                ]],
            ]],
        ]);

        $repair = $strategy->reduce(
            $this->step(maxRepairs: 2),
            $this->snapshot(state: $state),
            [$this->outcome($quoteFailure)],
        );

        self::assertSame('repair', $repair->stepState['phase'] ?? null);
        self::assertSame(0, $repair->stepState['repairs_used'] ?? null);
        self::assertSame(2, $repair->stepState['verification_retries_used'] ?? null);

        $missingUnitFailure = new CompletionResponse(structured: [
            'passed' => true,
            'claims' => [[
                'unit_id' => 'retell_01',
                'claim' => 'Wrong protocol identifier.',
                'source_quote' => 'Original target.',
                'verdict' => 'supported',
                'evidence' => [],
            ]],
        ]);
        $failed = $strategy->reduce(
            $this->step(maxRepairs: 2),
            $this->snapshot(state: $state),
            [$this->outcome($missingUnitFailure)],
        );

        self::assertSame('failed', $failed->stepState['phase'] ?? null);
        self::assertSame(0, $failed->stepState['repairs_used'] ?? null);
    }

    public function test_repair_defaults_malformed_state_and_returns_an_exact_continuation(): void
    {
        $outcome = $this->strategy()->reduce(
            $this->step(maxRepairs: 2),
            $this->snapshot(state: [
                'phase' => 'repair',
                'repairs_used' => 'invalid',
                'reports' => 'invalid',
                'violations' => [1, 'kept violation', null],
            ]),
            [$this->outcome(new CompletionResponse('Repaired target.', provider: 'repair-provider', model: 'repair-model'))],
        );

        self::assertTrue($outcome->continuesStep);
        self::assertSame([
            'phase' => 'verify',
            'repairs_used' => 1,
            'verification_retries_used' => 0,
            'reports' => [],
            'violations' => ['kept violation'],
            'protocol_violations' => [],
        ], $outcome->stepState);
        self::assertSame([
            'grounded_verification' => 'repaired',
            'repairs_used' => 1,
            'verification_retries_used' => 0,
        ], $outcome->metadata);
        self::assertCount(1, $outcome->artifacts);
        self::assertSame('verified', $outcome->artifacts[0]->key);
        self::assertSame('Repaired target.', $outcome->artifacts[0]->content);
        self::assertSame(['text' => 'Repaired target.'], $outcome->artifacts[0]->payload);
        self::assertSame([
            'source' => 'original',
            'operation_id' => 'rick.repair.text',
            'operation_version' => '1.0.0',
            'provider' => 'repair-provider',
            'model' => 'repair-model',
            'repaired_by' => 'rick.repair.text',
            'grounding_repairs' => 1,
        ], $outcome->artifacts[0]->metadata);
    }

    public function test_repair_plan_keeps_violations_but_drops_verbose_claim_history(): void
    {
        $plan = $this->strategy()->plan(
            $this->step(maxRepairs: 2),
            $this->snapshot(state: [
                'phase' => 'repair',
                'violations' => ['Bad evidence quote.'],
                'reports' => [[
                    'artifact_key' => 'target',
                    'passed' => false,
                    'violations' => ['Bad evidence quote.'],
                    'protocol_violations' => ['Bad evidence quote.'],
                    'content_violations' => [],
                    'missing_unit_ids' => [],
                    'claims' => [[
                        'evidence' => [['quote' => str_repeat('verbose transcript ', 1_000)]],
                    ]],
                ]],
            ]),
        );

        self::assertInstanceOf(InvocationStepPlan::class, $plan);
        $request = $plan->requests[0] ?? null;
        self::assertNotNull($request);
        $payload = json_decode(
            explode("\n\n", $request->messages[1]->content, 2)[1],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        $parameters = $payload['parameters'] ?? null;
        self::assertIsArray($parameters);
        self::assertSame(['Bad evidence quote.'], $parameters['violations'] ?? null);
        $reports = $parameters['reports'] ?? null;
        self::assertIsArray($reports);
        $report = $reports[0] ?? null;
        self::assertIsArray($report);
        self::assertArrayNotHasKey('claims', $report);
        self::assertStringNotContainsString('verbose transcript', $request->messages[1]->content);
    }

    private function strategy(int $batchSize = 20): GroundedVerifyStrategy
    {
        return new GroundedVerifyStrategy(
            $this->application()->make(LlmOperationRegistry::class),
            new GroundingSegmenter,
            new ExactQuoteVerifier,
            $batchSize,
        );
    }

    private function step(int $maxRepairs = 0): GroundedVerifyStep
    {
        return new GroundedVerifyStep(
            StepId::fromString('grounded'),
            'target',
            ['evidence'],
            repairOperationId: 'rick.repair.text',
            maxRepairs: $maxRepairs,
            outputKey: 'verified',
            minimumQuoteCharacters: 7,
        );
    }

    /** @param array<string, mixed> $state */
    private function snapshot(array $state = [], string $target = 'Original target.'): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('grounded-run'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Verify target',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            $state === [] ? [] : ['grounded' => $state],
            null,
            null,
            0,
            10,
            [
                'target' => new Artifact('target', ArtifactType::fromString('text'), $target, metadata: ['source' => 'original']),
                'evidence' => new Artifact('evidence', ArtifactType::fromString('text'), 'Original target is not supported.'),
            ],
        );
    }

    private function outcome(CompletionResponse $response): InvocationOutcome
    {
        return new InvocationOutcome(
            InvocationId::fromString('grounded-invocation'),
            0,
            1,
            InvocationStatus::Succeeded,
            $response,
            null,
            null,
        );
    }
}
