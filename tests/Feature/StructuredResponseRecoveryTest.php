<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicyRegistry;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunObservation;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Infrastructure\Configuration\RickConfiguration;
use Rick\Laravel\Infrastructure\Llm\ModelRouter;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class StructuredResponseRecoveryTest extends TestCase
{
    public function test_explicit_structured_retry_persists_both_paid_attempts(): void
    {
        config(['rick.llm.structured_responses.attempts' => 2]);
        $this->reloadConfiguration();
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $tiers = [];

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->calls++;
                $this->tiers[] = $request->modelTier;

                return new CompletionResponse(
                    structured: $this->calls === 1
                        ? ['wrong' => 'invalid-first-attempt']
                        : ['content' => 'Recovered candidate'],
                    provider: 'fake-provider',
                    model: 'fake-model',
                    metadata: [
                        'gateway_invocation_id' => 'gateway-'.$this->calls,
                        'provider_generation_id' => 'gen-'.$this->calls,
                        'provider_id_source' => 'body',
                    ],
                    metrics: new CompletionMetrics(
                        new TokenUsage($this->calls, 2),
                        InvocationCost::fromUsd('0.001'),
                        latencyMilliseconds: 10,
                    ),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $waiting = $rick->run($rick->workflow('structured-retry')
            ->resolve('Recover a candidate', 'One valid candidate is reviewable')
            ->generate('draft')
            ->manualJudge()
            ->build());

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertSame(2, $waiting->callsUsed);
        self::assertSame(2, $gateway->calls);
        self::assertSame(['medium', 'medium'], $gateway->tiers);
        self::assertSame('Recovered candidate', $rick->pendingReview($waiting->id)->candidates[0]->content);

        $metrics = $rick->metrics($waiting->id);
        self::assertSame(1, $metrics->totals->calls);
        self::assertSame(2, $metrics->totals->attempts);
        self::assertSame(2, $metrics->totals->providerRequests);
        self::assertSame(7, $metrics->totals->tokens->totalTokens);
        self::assertSame('0.002', $metrics->totals->cost->toUsdDecimal());
        self::assertCount(2, $metrics->invocations[0]->attemptDetails);
        self::assertSame(2, $metrics->toArray()['schema_version']);
        self::assertSame(2, $metrics->totals->toArray()['schema_version']);
        self::assertSame(2, $metrics->invocations[0]->toArray()['schema_version']);
        self::assertSame(
            1,
            $metrics->invocations[0]->attemptDetails[0]->toArray()['schema_version'],
        );
        self::assertSame(
            'structured_retry_same_route_scheduled',
            $metrics->invocations[0]->attemptDetails[0]->diagnostic?->retryDecision,
        );
        self::assertNotNull($metrics->invocations[0]->attemptDetails[0]->identifiers);
        self::assertSame('gateway-1', $metrics->invocations[0]->attemptDetails[0]
            ->identifiers->gatewayInvocationId);
        self::assertSame('gen-1', $metrics->invocations[0]->attemptDetails[0]
            ->identifiers->providerGenerationId);
        self::assertNotNull($metrics->invocations[0]->attemptDetails[1]->diagnostic);
    }

    public function test_structured_retry_uses_only_a_distinct_fallback_route(): void
    {
        config([
            'rick.llm.structured_responses.attempts' => 3,
            'rick.llm.policies.default.escalation_tiers' => ['quality'],
            'rick.llm.models.medium' => ['provider' => 'fake', 'model' => 'primary'],
            'rick.llm.models.quality' => ['provider' => 'fake', 'model' => 'fallback'],
        ]);
        $this->reloadConfiguration();
        $gateway = new class implements GatewayBase
        {
            /** @var list<string> */
            public array $tiers = [];

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->tiers[] = $request->modelTier;
                $attempt = count($this->tiers);

                return new CompletionResponse(
                    structured: $attempt < 3
                        ? ['wrong' => 'invalid-'.$attempt]
                        : ['content' => 'Fallback candidate'],
                    provider: 'fake',
                    model: $request->modelTier,
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run($rick->workflow('structured-fallback')
            ->resolve('Recover via fallback', 'A fallback candidate is produced')
            ->generate('draft')
            ->build());

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame(['medium', 'medium', 'quality'], $gateway->tiers);
        self::assertSame(3, $run->callsUsed);
        self::assertSame(3, $rick->metrics($run->id)->totals->attempts);
    }

    public function test_structured_retry_stops_at_the_explicit_attempt_limit(): void
    {
        config(['rick.llm.structured_responses.attempts' => 2]);
        $this->reloadConfiguration();
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->calls++;

                return new CompletionResponse(
                    structured: ['wrong' => 'invalid-'.$this->calls],
                    provider: 'fake',
                    model: 'fake',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run($rick->workflow('structured-retry-limit')
            ->resolve('Bound retries', 'The configured limit is enforced')
            ->generate('draft')
            ->build());
        $attempts = $rick->metrics($run->id)->invocations[0]->attemptDetails;

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(2, $gateway->calls);
        self::assertCount(2, $attempts);
        self::assertSame('structured_retry_exhausted', $attempts[1]->diagnostic?->retryDecision);
    }

    public function test_structured_fallback_rejects_a_duplicate_provider_route(): void
    {
        config([
            'rick.llm.structured_responses.attempts' => 3,
            'rick.llm.policies.default.escalation_tiers' => ['quality'],
            'rick.llm.models.medium' => ['provider' => 'fake', 'model' => 'same'],
            'rick.llm.models.quality' => ['provider' => 'fake', 'model' => 'same'],
        ]);
        $this->reloadConfiguration();
        $gateway = new class implements GatewayBase
        {
            /** @var list<string> */
            public array $tiers = [];

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->tiers[] = $request->modelTier;

                return new CompletionResponse(
                    structured: ['wrong' => 'invalid'],
                    provider: 'fake',
                    model: 'same',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run($rick->workflow('duplicate-fallback')
            ->resolve('Reject duplicate fallback', 'The route must actually change')
            ->generate('draft')
            ->build());
        $attempts = $rick->metrics($run->id)->invocations[0]->attemptDetails;

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(['medium', 'medium'], $gateway->tiers);
        self::assertCount(2, $attempts);
        self::assertSame('structured_fallback_unavailable', $attempts[1]->diagnostic?->retryDecision);

    }

    public function test_retry_is_not_sent_when_call_budget_is_exhausted(): void
    {
        config(['rick.llm.structured_responses.attempts' => 2]);
        $this->reloadConfiguration();
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->calls++;

                return new CompletionResponse(
                    structured: ['wrong' => 'invalid'],
                    provider: 'fake',
                    model: 'fake',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run(
            $rick->workflow('structured-budget')
                ->resolve('Do not overspend', 'Budget remains bounded')
                ->generate('draft')
                ->build(),
            callLimit: 1,
        );

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(1, $gateway->calls);
        self::assertSame(1, $run->callsUsed);
        self::assertSame(
            'retry_budget_rejected',
            $rick->metrics($run->id)->invocations[0]->attemptDetails[0]->diagnostic?->retryDecision,
        );
    }

    public function test_paid_failed_attempt_is_counted_before_retry_cost_budget_check(): void
    {
        config(['rick.llm.structured_responses.attempts' => 2]);
        $this->reloadConfiguration();
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $this->calls++;

                return new CompletionResponse(
                    structured: ['wrong' => 'invalid'],
                    provider: 'fake',
                    model: 'fake',
                    metrics: new CompletionMetrics(
                        new TokenUsage(3, 2),
                        InvocationCost::fromUsd('0.006'),
                    ),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run($rick->workflow('structured-cost-budget')
            ->budget(maxCostUsd: '0.005', requireKnownPricing: false)
            ->resolve('Do not repeat an over-budget response', 'The actual charge is counted')
            ->generate('draft')
            ->build());

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(1, $gateway->calls);
        self::assertSame('0.006', $rick->metrics($run->id)->totals->cost->toUsdDecimal());
        self::assertSame(
            'retry_budget_rejected',
            $rick->metrics($run->id)->invocations[0]->attemptDetails[0]
                ->diagnostic?->retryDecision,
        );
    }

    public function test_missing_usage_on_a_paid_invalid_response_remains_incomplete(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(
                    structured: ['wrong' => 'invalid'],
                    provider: 'fake',
                    model: 'fake',
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run($rick->workflow('structured-missing-usage')
            ->resolve('Keep missing usage explicit', 'Zero is not treated as complete usage')
            ->generate('draft')
            ->build());
        $metrics = $rick->metrics($run->id);
        $attempt = $metrics->invocations[0]->attemptDetails[0];

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(1, $metrics->totals->incompleteUsageAttempts);
        self::assertNotNull($attempt->metrics);
        self::assertNotNull($attempt->diagnostic);
        self::assertFalse($attempt->metrics->usagePresent);
        self::assertFalse($attempt->metrics->usageComplete);
        self::assertFalse($attempt->diagnostic->usagePresent);
        self::assertFalse($attempt->diagnostic->usageComplete);
        self::assertSame(
            1,
            $this->application()->make(ConnectionInterface::class)
                ->table('rick_outbox')
                ->where('event_type', 'usage.recorded')
                ->count(),
        );
    }

    public function test_candidate_quorum_opens_review_with_original_provenance(): void
    {
        $gateway = new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );

                return new CompletionResponse(
                    structured: $index === 2
                        ? ['wrong' => 'candidate-three-failed']
                        : ['content' => 'Candidate body '.($index + 1)],
                    provider: 'fake',
                    model: 'fake',
                    metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $waiting = $rick->run($rick->workflow('candidate-quorum')
            ->resolve('Generate five candidates', 'Four candidates are enough')
            ->plan(candidates: 5, minimumSuccessful: 4)
            ->manualJudge()
            ->build());
        $review = $rick->pendingReview($waiting->id);

        self::assertSame(RunStatus::AwaitingInput, $waiting->status);
        self::assertCount(4, $review->candidates);
        self::assertSame(
            ['Candidate 1', 'Candidate 2', 'Candidate 4', 'Candidate 5'],
            array_map(static fn ($candidate): string => $candidate->title, $review->candidates),
        );
        self::assertSame(
            [0, 1, 3, 4],
            array_map(
                static fn ($candidate): int => JsonInput::integer(
                    $candidate->metadata['original_index'] ?? null,
                    'candidate.metadata.original_index',
                ),
                $review->candidates,
            ),
        );
        foreach ($review->candidates as $candidate) {
            self::assertSame($candidate->seedRandomString, $candidate->metadata['invocation_id']);
        }
        $metrics = $rick->metrics($waiting->id);
        self::assertSame(4, $metrics->totals->succeededCalls);
        self::assertSame(1, $metrics->totals->failedCalls);
        self::assertSame(10, $metrics->totals->tokens->totalTokens);
        $degraded = array_values(array_filter(
            $rick->timeline($waiting->id)->observations,
            static fn (RunObservation $observation): bool => $observation->type === 'step.degraded',
        ));
        self::assertCount(1, $degraded);
        self::assertSame(5, $degraded[0]->details['expected']);
        self::assertSame(4, $degraded[0]->details['succeeded']);
        self::assertSame(['provider_response_invalid'], $degraded[0]->details['failure_codes']);

        $attemptFailures = array_values(array_filter(
            $rick->timeline($waiting->id)->observations,
            static fn (RunObservation $observation): bool => $observation->type === 'invocation.attempt.failed',
        ));
        self::assertCount(1, $attemptFailures);
        self::assertNotNull($attemptFailures[0]->attemptId);
        self::assertSame(2, $attemptFailures[0]->details['original_index']);
        self::assertSame(3, $attemptFailures[0]->details['candidate_number']);
        self::assertSame('schema_validation', $attemptFailures[0]->details['validation_stage']);
        self::assertSame('additionalProperties', $attemptFailures[0]->details['validation_keyword']);
        self::assertArrayNotHasKey('raw_response', $attemptFailures[0]->details);
        self::assertSame(2, $attemptFailures[0]->toArray()['schema_version']);
    }

    public function test_all_required_and_unreachable_quorum_remain_terminal_failures(): void
    {
        foreach ([null, 4] as $minimum) {
            $gateway = new class implements GatewayBase
            {
                public function complete(CompletionRequest $request): CompletionResponse
                {
                    $index = JsonInput::integer(
                        $request->metadata['candidate_index'] ?? null,
                        'request.metadata.candidate_index',
                    );

                    return new CompletionResponse(
                        structured: $index >= 3
                            ? ['wrong' => 'failed-'.$index]
                            : ['content' => 'Candidate '.($index + 1)],
                        provider: 'fake',
                        model: 'fake',
                        metrics: new CompletionMetrics(new TokenUsage(1, 1)),
                    );
                }
            };
            $this->application()->instance(GatewayBase::class, $gateway);
            $rick = $this->application()->make(Rick::class);
            $builder = $rick->workflow('terminal-quorum-'.($minimum ?? 'all'))
                ->resolve('Generate five candidates', 'The completion policy is enforced');
            $builder->plan(candidates: 5, minimumSuccessful: $minimum);
            $run = $rick->run($builder->build());

            self::assertSame(RunStatus::Failed, $run->status);
            self::assertSame(3, $rick->metrics($run->id)->totals->succeededCalls);
            self::assertSame(2, $rick->metrics($run->id)->totals->failedCalls);

            $timeline = $rick->timeline($run->id)->observations;
            $terminalVersions = array_map(
                static fn (RunObservation $observation): int => $observation->version,
                array_values(array_filter(
                    $timeline,
                    static fn (RunObservation $observation): bool => $observation->type === 'run.terminal',
                )),
            );
            $attemptTerminalVersions = array_map(
                static fn (RunObservation $observation): int => $observation->version,
                array_values(array_filter(
                    $timeline,
                    static fn (RunObservation $observation): bool => str_starts_with(
                        $observation->type,
                        'invocation.attempt.',
                    ),
                )),
            );
            if ($terminalVersions === [] || $attemptTerminalVersions === []) {
                self::fail('Expected terminal run and invocation-attempt observations.');
            }
            self::assertGreaterThan(
                max($attemptTerminalVersions),
                min($terminalVersions),
                'A run must not become terminal before every dispatched paid request is terminal.',
            );
        }
    }

    private function reloadConfiguration(): void
    {
        $configured = config('rick');
        self::assertIsArray($configured);
        $this->application()->instance(
            RickConfiguration::class,
            RickConfiguration::from(
                JsonInput::map($configured, 'rick'),
                $this->application()->make(JsonSchemaValidatorBase::class),
            ),
        );
        $this->application()->forgetInstance(ModelPolicyRegistry::class);
        $this->application()->forgetInstance(ModelRouter::class);
    }
}
