<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Exception\ExecutionRecordNotFoundException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunObservation;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\TestCase;

final class ObservabilityReadModelTest extends TestCase
{
    public function test_read_model_accepts_exact_page_boundaries_and_rejects_each_adjacent_value(): void
    {
        Queue::fake();
        $rick = $this->application()->make(Rick::class);
        $run = $rick->schedule(
            $rick->workflow('read-boundaries')
                ->resolve('Boundary', 'Boundary is persisted')
                ->build(),
        );

        self::assertCount(1, $rick->runs(limit: 1)->runs);
        self::assertCount(1, $rick->runs(limit: 100)->runs);
        self::assertSame(0, $rick->timeline($run->id, 0)->runVersion);
        foreach ([-1, 0, 101, 102] as $invalidLimit) {
            $caught = false;
            try {
                $rick->runs(limit: $invalidLimit);
            } catch (InvalidArgumentException $error) {
                $caught = true;
                self::assertSame('Run page limit must be between 1 and 100.', $error->getMessage());
            }
            self::assertTrue($caught, "Limit {$invalidLimit} must be rejected.");
        }

        $caught = false;
        try {
            $rick->timeline($run->id, -1);
        } catch (InvalidArgumentException $error) {
            $caught = true;
            self::assertSame('Timeline version must not be negative.', $error->getMessage());
        }
        self::assertTrue($caught, 'Negative timeline versions must be rejected.');
    }

    public function test_run_index_is_bounded_cursor_paginated_status_filtered_and_tenant_scoped(): void
    {
        Queue::fake();
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('indexed-run')
            ->resolve('Index this run', 'The run is indexed')
            ->build();

        $expected = [];
        for ($index = 0; $index < 3; $index++) {
            $expected[] = $rick->schedule($workflow)->id->toString();
        }
        $completed = $rick->run($workflow);
        $expected[] = $completed->id->toString();

        $first = $rick->runs(limit: 2);
        self::assertCount(2, $first->runs);
        self::assertNotNull($first->nextCursor);
        $second = $rick->runs($first->nextCursor, limit: 2);
        self::assertCount(2, $second->runs);
        self::assertNull($second->nextCursor);
        $listed = array_map(
            static fn ($run): string => $run->id->toString(),
            [...$first->runs, ...$second->runs],
        );
        self::assertEqualsCanonicalizing($expected, $listed);
        self::assertCount(count($listed), array_unique($listed));

        $completedPage = $rick->runs(status: RunStatus::Completed);
        self::assertCount(1, $completedPage->runs);
        self::assertSame($completed->id->toString(), $completedPage->runs[0]->id->toString());
        self::assertSame(
            [RunStatus::Created, RunStatus::Created, RunStatus::Created],
            array_map(
                static fn ($run): RunStatus => $run->status,
                $rick->runs(status: RunStatus::Created)->runs,
            ),
        );

        foreach ([0, 101] as $invalidLimit) {
            try {
                $rick->runs(limit: $invalidLimit);
                self::fail('An unbounded run page should be rejected.');
            } catch (InvalidArgumentException) {
            }
        }
        try {
            $rick->runs(cursor: 'not-a-valid-cursor');
            self::fail('An invalid run cursor should be rejected.');
        } catch (InvalidArgumentException) {
        }
        try {
            $rick->runs($first->nextCursor, RunStatus::Completed);
            self::fail('A run cursor should remain bound to its status filter.');
        } catch (InvalidArgumentException) {
        }

        $tenant = $this->application()->make(TenantContextBase::class);
        $tenantRun = $tenant->run('tenant-b', fn () => $rick->schedule($workflow));
        self::assertCount(4, $rick->runs()->runs);
        $tenant->run('tenant-b', function () use ($completed, $first, $rick, $tenantRun): void {
            $page = $rick->runs();
            self::assertCount(1, $page->runs);
            self::assertSame($tenantRun->id->toString(), $page->runs[0]->id->toString());

            try {
                $rick->timeline($completed->id);
                self::fail('A run from another tenant should not be visible.');
            } catch (ExecutionRecordNotFoundException) {
            }
            try {
                $rick->runs($first->nextCursor);
                self::fail('A run cursor from another tenant should be rejected.');
            } catch (InvalidArgumentException) {
            }
        });
    }

    public function test_timeline_and_delivery_are_stable_incremental_and_redacted(): void
    {
        Queue::fake();
        $secretPrompt = 'timeline-secret-prompt';
        $secretCandidate = 'timeline-secret-candidate';
        $gateway = (new FakeGateway)->respondUsing(
            static function (CompletionRequest $request) use ($secretCandidate): CompletionResponse {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );

                return new CompletionResponse(
                    structured: ['content' => $secretCandidate.'-'.$index],
                    provider: 'fake-observability',
                    model: 'fake-model',
                    metadata: ['request_id' => 'fake-request-'.$index],
                    metrics: new CompletionMetrics(new TokenUsage(10 + $index, 5)),
                );
            },
        );
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('observable-review')
            ->resolve($secretPrompt, 'Two safe plans exist')
            ->plan(candidates: 2)
            ->manualJudge()
            ->outputGlue('plan')
            ->build();

        $waiting = $rick->run($workflow);
        $review = $rick->pendingReview($waiting->id);
        $before = $rick->timeline($waiting->id);
        $beforeAgain = $rick->timeline($waiting->id);
        $gateway->assertRequested(
            static fn (CompletionRequest $request): bool => $request->purpose === 'generate_candidate',
            times: 2,
        );

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertCount(2, $review->candidates);
        self::assertSame(
            self::observationVersions($before->observations),
            self::observationVersions($beforeAgain->observations),
        );
        $types = array_map(
            static fn (RunObservation $observation): string => $observation->type,
            $before->observations,
        );
        foreach ([
            'run.scheduled',
            'invocation.planned',
            'invocation.leased',
            'invocation.succeeded',
            'manual.review.opened',
            'outbox.pending',
            'outbox.claimed',
            'outbox.delivered',
        ] as $type) {
            self::assertContains($type, $types);
        }
        $planned = self::observationsOfType($before->observations, 'invocation.planned');
        $leased = self::observationsOfType($before->observations, 'invocation.leased');
        $succeeded = self::observationsOfType($before->observations, 'invocation.succeeded');
        $attempts = self::observationsOfType($before->observations, 'invocation.attempt.succeeded');
        self::assertCount(2, $planned);
        self::assertCount(2, $leased);
        self::assertCount(2, $succeeded);
        self::assertCount(2, $attempts);
        foreach ($planned as $index => $observation) {
            self::assertSame('generate_candidate', $observation->operationKey);
            self::assertNotNull($observation->stepId);
            self::assertNotNull($observation->invocationId);
            self::assertNull($observation->attempt);
            self::assertNull($observation->attemptId);
            self::assertSame([
                'step_execution_id',
                'original_index',
                'candidate_number',
                'source_run_id',
                'source_invocation_id',
                'reused',
            ], array_keys($observation->details));
            self::assertSame($index, $observation->details['original_index']);
            self::assertSame($index + 1, $observation->details['candidate_number']);
            self::assertNull($observation->details['source_run_id']);
            self::assertNull($observation->details['source_invocation_id']);
            self::assertFalse($observation->details['reused']);
        }
        foreach ($leased as $index => $observation) {
            self::assertSame('generate_candidate', $observation->operationKey);
            self::assertSame(1, $observation->attempt);
            self::assertNotNull($observation->attemptId);
            self::assertSame([
                'step_execution_id',
                'original_index',
                'candidate_number',
            ], array_keys($observation->details));
            self::assertSame($index, $observation->details['original_index']);
            self::assertSame($index + 1, $observation->details['candidate_number']);
        }
        foreach ($succeeded as $index => $observation) {
            self::assertSame([
                'step_execution_id',
                'original_index',
                'candidate_number',
                'error_code',
                'terminal_timestamp',
                'source_run_id',
                'source_invocation_id',
                'reused',
            ], array_keys($observation->details));
            self::assertSame($index, $observation->details['original_index']);
            self::assertSame($index + 1, $observation->details['candidate_number']);
            self::assertNull($observation->details['error_code']);
            self::assertSame($observation->occurredAt->format(DATE_ATOM), $observation->details['terminal_timestamp']);
            self::assertFalse($observation->details['reused']);
        }
        foreach ($attempts as $index => $observation) {
            self::assertSame('generate_candidate', $observation->operationKey);
            self::assertSame(1, $observation->attempt);
            self::assertNotNull($observation->attemptId);
            self::assertSame([
                'step_execution_id',
                'original_index',
                'candidate_number',
                'error_code',
                'http_status_class',
                'gateway_invocation_id',
                'provider_request_id',
                'provider_generation_id',
                'provider_id_source',
                'provider_request_outcome',
                'provider',
                'model',
                'resolved_route',
                'model_tier',
                'tokens',
                'cost_usd',
                'latency_milliseconds',
                'provider_requests',
                'usage_present',
                'usage_complete',
                'prompt_characters',
                'response_characters',
                'validation_stage',
                'contract',
                'schema_fingerprint',
                'response_present',
                'response_bytes',
                'response_fingerprint',
                'decode_status',
                'expected_root_type',
                'actual_root_type',
                'validation_path',
                'validation_keyword',
                'finish_reason',
                'retry_decision',
                'terminal_timestamp',
            ], array_keys($observation->details));
            self::assertSame($index, $observation->details['original_index']);
            self::assertSame($index + 1, $observation->details['candidate_number']);
            self::assertSame('fake-request-'.$index, $observation->details['provider_request_id']);
            self::assertSame('fake-observability', $observation->details['provider']);
            self::assertSame('fake-model', $observation->details['model']);
            self::assertSame('fake-observability:fake-model', $observation->details['resolved_route']);
            self::assertSame([
                'input_tokens' => 10 + $index,
                'output_tokens' => 5,
                'total_tokens' => 15 + $index,
                'cached_input_tokens' => 0,
                'cache_write_input_tokens' => 0,
                'reasoning_tokens' => 0,
            ], $observation->details['tokens']);
            self::assertNull($observation->details['cost_usd']);
            self::assertNull($observation->details['latency_milliseconds']);
            self::assertSame(1, $observation->details['provider_requests']);
            self::assertTrue($observation->details['usage_present']);
            self::assertTrue($observation->details['usage_complete']);
            self::assertGreaterThan(0, $observation->details['prompt_characters']);
            self::assertGreaterThan(0, $observation->details['response_characters']);
            self::assertSame('decode', $observation->details['validation_stage']);
            self::assertSame('candidate', $observation->details['contract']);
            self::assertIsString($observation->details['schema_fingerprint']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $observation->details['schema_fingerprint']);
            self::assertTrue($observation->details['response_present']);
            self::assertSame($observation->details['response_characters'], $observation->details['response_bytes']);
            self::assertIsString($observation->details['response_fingerprint']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $observation->details['response_fingerprint']);
            self::assertSame('object', $observation->details['decode_status']);
            self::assertSame('object', $observation->details['expected_root_type']);
            self::assertSame('object', $observation->details['actual_root_type']);
            self::assertNull($observation->details['validation_path']);
            self::assertNull($observation->details['validation_keyword']);
            self::assertNull($observation->details['finish_reason']);
            self::assertNull($observation->details['retry_decision']);
            self::assertSame($observation->occurredAt->format(DATE_ATOM), $observation->details['terminal_timestamp']);
        }
        $ids = array_keys(self::observationVersions($before->observations));
        self::assertCount(count($ids), array_unique($ids));

        $selection = $rick->selectCandidate(
            $waiting->id->toString(),
            $review->candidates[1]->id->toString(),
        );
        self::assertTrue($selection->continuationQueued);
        self::assertSame(RunStatus::Running, $selection->status);
        self::assertGreaterThan($waiting->version, $selection->version);

        $complete = $rick->timeline($waiting->id);
        $delta = $rick->timeline($waiting->id, $before->latestVersion);
        foreach (self::observationVersions($before->observations) as $id => $version) {
            self::assertSame($version, self::observationVersions($complete->observations)[$id]);
        }
        self::assertNotEmpty($delta->observations);
        foreach ($delta->observations as $observation) {
            self::assertGreaterThan($before->latestVersion, $observation->version);
        }
        $deltaTypes = array_map(
            static fn (RunObservation $observation): string => $observation->type,
            $delta->observations,
        );
        self::assertContains(
            'manual.review.resolved',
            $deltaTypes,
            json_encode($deltaTypes, JSON_THROW_ON_ERROR),
        );
        self::assertContains('run.continued', $deltaTypes);

        $delivery = $rick->delivery($waiting->id);
        self::assertNotEmpty($delivery->records);
        self::assertSame(count($delivery->records), array_sum($delivery->counts));
        self::assertSame(0, $delivery->counts['claimed']);
        self::assertSame(0, $delivery->counts['quarantined']);

        $transport = json_encode(
            ['timeline' => $complete, 'delivery' => $delivery],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        self::assertStringNotContainsString($secretPrompt, $transport);
        self::assertStringNotContainsString($secretCandidate, $transport);
        self::assertStringNotContainsString('request_payload', $transport);
        self::assertStringNotContainsString('payload', $transport);
        self::assertSame(1, $complete->toArray()['schema_version']);
        self::assertSame(1, $delivery->toArray()['schema_version']);
    }

    /**
     * @param  list<RunObservation>  $observations
     * @return array<string, int>
     */
    private static function observationVersions(array $observations): array
    {
        $versions = [];
        foreach ($observations as $observation) {
            $versions[$observation->id] = $observation->version;
        }

        return $versions;
    }

    /**
     * @param  list<RunObservation>  $observations
     * @return list<RunObservation>
     */
    private static function observationsOfType(array $observations, string $type): array
    {
        $matches = array_values(array_filter(
            $observations,
            static fn (RunObservation $observation): bool => $observation->type === $type,
        ));
        usort(
            $matches,
            static fn (RunObservation $left, RunObservation $right): int => ($left->details['original_index'] ?? 0)
                <=> ($right->details['original_index'] ?? 0),
        );

        return $matches;
    }
}
