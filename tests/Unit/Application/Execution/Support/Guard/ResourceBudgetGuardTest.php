<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Guard;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Guard\ResourceBudgetGuard;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Domain\Exception\ResourceBudgetExceededException;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class ResourceBudgetGuardTest extends TestCase
{
    public function test_dispatch_reserves_exact_input_output_total_and_known_cost(): void
    {
        $guard = $this->guard(InvocationCost::fromUsd('0.1'));
        $budget = new ResourceBudget(
            maxInputTokens: 2,
            maxOutputTokens: 3,
            maxTotalTokens: 5,
            maxCost: InvocationCost::fromUsd('0.1'),
            defaultOutputReservationTokens: 10,
        );

        $guard->assertCanDispatch($this->snapshot($budget), [$this->request(['max_tokens' => 3])]);
        self::addToAssertionCount(1);
    }

    public function test_dispatch_uses_default_and_alternate_output_options(): void
    {
        $guard = $this->guard(InvocationCost::zero());
        $budget = new ResourceBudget(maxOutputTokens: 7, defaultOutputReservationTokens: 4);

        $guard->assertCanDispatch($this->snapshot($budget), [
            $this->request(),
            $this->request(['max_output_tokens' => '3']),
        ]);
        self::addToAssertionCount(1);
    }

    public function test_each_dispatch_budget_limit_fails_with_its_exact_resource(): void
    {
        $cases = [
            'input_tokens' => [new ResourceBudget(maxInputTokens: 1), 2, 1],
            'output_tokens' => [new ResourceBudget(maxOutputTokens: 2), 3, 2],
            'total_tokens' => [new ResourceBudget(maxTotalTokens: 4), 5, 4],
            'cost_usd' => [new ResourceBudget(maxCost: InvocationCost::fromUsd('0.09')), '0.1', '0.09'],
        ];

        foreach ($cases as $resource => [$budget, $actual, $limit]) {
            try {
                $this->guard(InvocationCost::fromUsd('0.1'))->assertCanDispatch(
                    $this->snapshot($budget),
                    [$this->request(['max_tokens' => 3])],
                );
                self::fail("Budget [{$resource}] was not enforced.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame($resource, $error->resource);
                self::assertSame($actual, $error->actual);
                self::assertSame($limit, $error->limit);
            }
        }
    }

    public function test_unknown_pricing_fails_only_when_cost_is_bounded_and_pricing_is_required(): void
    {
        $strict = new ResourceBudget(
            maxCost: InvocationCost::fromUsd('1'),
            requireKnownPricing: true,
        );
        try {
            $this->guard(null)->assertCanDispatch($this->snapshot($strict), [$this->request()]);
            self::fail('Unknown pricing was accepted.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame('known_pricing', $error->resource);
        }

        $permissive = new ResourceBudget(
            maxCost: InvocationCost::fromUsd('1'),
            requireKnownPricing: false,
        );
        $this->guard(null)->assertCanDispatch($this->snapshot($permissive), [$this->request()]);
        self::addToAssertionCount(1);
    }

    public function test_current_duration_uses_non_negative_milliseconds_and_unbounded_runs_return(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:01.000000+00:00');
        $guard = $this->guard(InvocationCost::zero(), $now);
        try {
            $guard->assertCurrent($this->snapshot(
                new ResourceBudget(maxDurationMilliseconds: 999),
                new DateTimeImmutable('2026-08-08T10:00:00.000000+00:00'),
            ));
            self::fail('Elapsed duration was not enforced.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame('duration_milliseconds', $error->resource);
            self::assertSame(1000, $error->actual);
        }

        $guard->assertCurrent($this->snapshot(ResourceBudget::unbounded(), $now->modify('+1 second')));
        $guard->assertCanDispatch($this->snapshot(ResourceBudget::unbounded()), [$this->request()]);
        $guard->assertCurrent($this->snapshot(new ResourceBudget(maxDurationMilliseconds: 1)));
        self::addToAssertionCount(3);
    }

    public function test_duration_rounding_and_future_start_boundaries_are_exact(): void
    {
        $guard = $this->guard(
            InvocationCost::zero(),
            new DateTimeImmutable('2026-08-08T10:00:00.001000+00:00'),
        );
        $guard->assertCurrent($this->snapshot(
            new ResourceBudget(maxDurationMilliseconds: 1),
            new DateTimeImmutable('2026-08-08T10:00:00.000400+00:00'),
        ));
        self::addToAssertionCount(1);

        try {
            $guard->assertCurrent($this->snapshot(
                new ResourceBudget(maxDurationMilliseconds: 1),
                new DateTimeImmutable('2026-08-08T09:59:59.999400+00:00'),
            ));
            self::fail('A rounded millisecond above the zero limit was accepted.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame(2, $error->actual);
        }

        $guard->assertCurrent($this->snapshot(
            new ResourceBudget(maxDurationMilliseconds: 1),
            new DateTimeImmutable('2026-08-08T10:00:00.002000+00:00'),
        ));
        $guard->assertCurrent($this->snapshot(
            new ResourceBudget(maxDurationMilliseconds: 1),
            new DateTimeImmutable('2026-08-08T09:59:59.999600+00:00'),
        ));
        self::addToAssertionCount(1);
    }

    public function test_dispatch_sums_each_message_and_request_with_minimum_reservations(): void
    {
        $request = new CompletionRequest(
            [
                new Message('system', 'x'),
                new Message('user', '1234'),
                new Message('assistant', '12345'),
            ],
            ResponseContract::Text,
            'budget',
            options: ['max_output_tokens' => 0],
        );
        $this->guard(InvocationCost::fromUsd('0.02'))->assertCanDispatch(
            $this->snapshot(new ResourceBudget(
                maxInputTokens: 6,
                maxOutputTokens: 2,
                maxTotalTokens: 8,
                maxCost: InvocationCost::fromUsd('0.04'),
            )),
            [$request, $this->request(['max_tokens' => 1])],
        );
        self::addToAssertionCount(1);

        try {
            $this->guard(InvocationCost::zero())->assertCanDispatch(
                $this->snapshot(new ResourceBudget(maxInputTokens: 5)),
                [$request, $this->request(['max_tokens' => 1])],
            );
            self::fail('The exact per-message input reservation was not enforced.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame(['input_tokens', 6, 5], [$error->resource, $error->actual, $error->limit]);
        }

        $precedence = $this->request(['max_output_tokens' => 2, 'max_tokens' => 20]);
        $this->guard(InvocationCost::zero())->assertCanDispatch(
            $this->snapshot(new ResourceBudget(maxOutputTokens: 2)),
            [$precedence],
        );
        self::addToAssertionCount(1);
    }

    public function test_dispatch_enforces_current_usage_before_reserving_the_next_request(): void
    {
        $attempt = $this->attempt($this->metrics(new TokenUsage(2, 1), '0.1', 3));

        try {
            $this->guard(
                InvocationCost::zero(),
                attempts: [$attempt],
                invocations: [$this->invocation('inv-measured')],
            )->assertCanDispatch(
                $this->snapshot(new ResourceBudget(maxInputTokens: 1)),
                [$this->request(['max_tokens' => 1])],
            );
            self::fail('Current usage was skipped before dispatch reservation.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame(['input_tokens', 2, 1], [$error->resource, $error->actual, $error->limit]);
        }
    }

    public function test_current_usage_aggregates_measured_attempts_and_enforces_every_limit(): void
    {
        $attempt = $this->attempt($this->metrics(new TokenUsage(3, 5), '0.25', 17));
        $invocation = $this->invocation('inv-measured');
        $guard = $this->guard(InvocationCost::zero(), attempts: [$attempt], invocations: [$invocation]);

        $guard->assertCurrent($this->snapshot(new ResourceBudget(
            maxInputTokens: 3,
            maxOutputTokens: 5,
            maxTotalTokens: 8,
            maxCost: InvocationCost::fromUsd('0.25'),
            maxLatencyMilliseconds: 17,
        )));

        $cases = [
            'input_tokens' => [new ResourceBudget(maxInputTokens: 2), 3, 2],
            'output_tokens' => [new ResourceBudget(maxOutputTokens: 4), 5, 4],
            'total_tokens' => [new ResourceBudget(maxTotalTokens: 7), 8, 7],
            'cost_usd' => [new ResourceBudget(maxCost: InvocationCost::fromUsd('0.24')), '0.25', '0.24'],
            'latency_milliseconds' => [new ResourceBudget(maxLatencyMilliseconds: 16), 17, 16],
        ];
        foreach ($cases as $resource => [$budget, $actual, $limit]) {
            try {
                $guard->assertCurrent($this->snapshot($budget));
                self::fail("Current usage [{$resource}] was not enforced.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame($resource, $error->resource);
                self::assertSame($actual, $error->actual);
                self::assertSame($limit, $error->limit);
            }
        }
    }

    public function test_current_usage_aggregates_multiple_invocations_and_skips_unmeasured_attempt_rows(): void
    {
        $attempts = [
            $this->attemptFor('attempt-a', 'inv-a', $this->metrics(new TokenUsage(1, 2), '0.1', 3)),
            $this->attemptWithoutMetrics('attempt-a-empty', 'inv-a'),
            $this->attemptFor('attempt-b', 'inv-b', $this->metrics(new TokenUsage(4, 8), '0.2', 5)),
        ];
        $guard = $this->guard(
            InvocationCost::zero(),
            attempts: $attempts,
            invocations: [$this->invocation('inv-a'), $this->invocation('inv-b')],
        );

        $guard->assertCurrent($this->snapshot(new ResourceBudget(
            maxInputTokens: 5,
            maxOutputTokens: 10,
            maxTotalTokens: 15,
            maxCost: InvocationCost::fromUsd('0.3'),
            maxLatencyMilliseconds: 8,
            requireCompleteMetrics: true,
            requireKnownPricing: true,
        )));

        foreach ([
            'input_tokens' => [new ResourceBudget(maxInputTokens: 4), 5, 4],
            'output_tokens' => [new ResourceBudget(maxOutputTokens: 9), 10, 9],
            'total_tokens' => [new ResourceBudget(maxTotalTokens: 14), 15, 14],
            'cost_usd' => [new ResourceBudget(maxCost: InvocationCost::fromUsd('0.29')), '0.3', '0.29'],
            'latency_milliseconds' => [new ResourceBudget(maxLatencyMilliseconds: 7), 8, 7],
        ] as $resource => [$budget, $actual, $limit]) {
            try {
                $guard->assertCurrent($this->snapshot($budget));
                self::fail("Aggregate [{$resource}] was not enforced.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame([$resource, $actual, $limit], [$error->resource, $error->actual, $error->limit]);
            }
        }
    }

    public function test_pending_attempt_respects_provider_outcome_and_metric_completeness(): void
    {
        $metrics = $this->metrics(new TokenUsage(2, 3), null, 7, false, false);
        $strict = $this->snapshot(new ResourceBudget(
            maxCost: InvocationCost::fromUsd('1'),
            requireCompleteMetrics: true,
            requireKnownPricing: true,
        ));
        $guard = $this->guard(InvocationCost::zero());

        $guard->assertCanDispatch($strict, [$this->request()], $metrics, ProviderRequestOutcome::NotAccepted);
        self::addToAssertionCount(1);

        foreach ([ProviderRequestOutcome::ResponseReceived, ProviderRequestOutcome::Indeterminate] as $outcome) {
            try {
                $guard->assertCanDispatch($strict, [$this->request()], $metrics, $outcome);
                self::fail("Incomplete {$outcome->value} metrics were accepted.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame('complete_metrics', $error->resource);
                self::assertSame(2, $error->actual);
                self::assertSame(0, $error->limit);
            }
        }
    }

    public function test_pending_metric_completeness_and_pricing_truth_table_is_fail_closed(): void
    {
        $guard = $this->guard(InvocationCost::zero());
        $completeStrict = $this->snapshot(new ResourceBudget(
            maxCost: InvocationCost::fromUsd('1'),
            requireCompleteMetrics: true,
            requireKnownPricing: true,
        ));
        $cases = [
            'present and complete with price' => [$this->metrics(new TokenUsage(1, 1), '0.1', null, true, true), ProviderRequestOutcome::ResponseReceived, false],
            'missing usage' => [$this->metrics(new TokenUsage(1, 1), '0.1', null, false, false), ProviderRequestOutcome::ResponseReceived, true],
            'incomplete usage' => [$this->metrics(new TokenUsage(1, 1), '0.1', null, true, false), ProviderRequestOutcome::ResponseReceived, true],
            'missing usage and pricing' => [$this->metrics(new TokenUsage(1, 1), null, null, false, false), ProviderRequestOutcome::Indeterminate, true],
            'complete but unpriced' => [$this->metrics(new TokenUsage(1, 1), null, null, true, true), ProviderRequestOutcome::ResponseReceived, true],
            'not accepted has no required telemetry' => [$this->metrics(new TokenUsage(1, 1), null, null, false, false), ProviderRequestOutcome::NotAccepted, false],
        ];

        foreach ($cases as $label => [$metrics, $outcome, $fails]) {
            try {
                $guard->assertCanDispatch($completeStrict, [$this->request()], $metrics, $outcome);
                self::assertFalse($fails, "{$label} should have failed.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertTrue($fails, "{$label} unexpectedly failed.");
                self::assertSame('complete_metrics', $error->resource);
                self::assertSame($label === 'missing usage and pricing' ? 2 : 1, $error->actual);
                self::assertSame(0, $error->limit);
            }
        }
    }

    public function test_pending_attempt_is_included_exactly_in_every_measured_limit(): void
    {
        $guard = $this->guard(InvocationCost::fromUsd('0.02'));
        $pending = $this->metrics(new TokenUsage(2, 3), '0.1', 7);
        $request = $this->request(['max_tokens' => 1]);
        $cases = [
            'input_tokens' => [new ResourceBudget(maxInputTokens: 3), 4, 3],
            'output_tokens' => [new ResourceBudget(maxOutputTokens: 3), 4, 3],
            'total_tokens' => [new ResourceBudget(maxTotalTokens: 7), 8, 7],
            'cost_usd' => [new ResourceBudget(maxCost: InvocationCost::fromUsd('0.11')), '0.12', '0.11'],
            'latency_milliseconds' => [new ResourceBudget(maxLatencyMilliseconds: 6), 7, 6],
        ];

        foreach ($cases as $resource => [$budget, $actual, $limit]) {
            try {
                $guard->assertCanDispatch(
                    $this->snapshot($budget),
                    [$request],
                    $pending,
                    ProviderRequestOutcome::ResponseReceived,
                );
                self::fail("Pending usage [{$resource}] was not enforced.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame([$resource, $actual, $limit], [$error->resource, $error->actual, $error->limit]);
            }
        }
    }

    public function test_persisted_attempt_completeness_and_pricing_counts_are_exact(): void
    {
        $attempt = $this->attempt($this->metrics(new TokenUsage(1, 1), null, null, false, false));
        $guard = $this->guard(
            InvocationCost::zero(),
            attempts: [$attempt],
            invocations: [$this->invocation('inv-measured')],
        );

        try {
            $guard->assertCurrent($this->snapshot(new ResourceBudget(
                maxTotalTokens: 10,
                maxCost: InvocationCost::fromUsd('1'),
                requireCompleteMetrics: true,
                requireKnownPricing: true,
            )));
            self::fail('Incomplete and unpriced attempt metrics were accepted.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame(['complete_metrics', 2, 0], [$error->resource, $error->actual, $error->limit]);
        }
    }

    public function test_reused_and_missing_metric_invocations_do_not_stop_later_aggregation(): void
    {
        $source = $this->invocation('inv-source-order');
        $source->start();
        $source->succeed(new CompletionResponse('reused'));
        $reused = LlmInvocation::reused(
            InvocationId::fromString('inv-reused-order'),
            StepExecutionId::fromString('execution-reused-order'),
            RunId::fromString('run-budget'),
            StepId::fromString('step-budget'),
            0,
            $this->request(),
            $source,
        );
        $missing = $this->invocation('inv-missing-order');
        $measured = $this->invocation('inv-measured-order');
        $measured->start();
        $measured->succeed(new CompletionResponse(metrics: new CompletionMetrics(
            new TokenUsage(3, 4),
            InvocationCost::fromUsd('0.2'),
            5,
        )));
        $guard = $this->guard(
            InvocationCost::zero(),
            invocations: [$reused, $missing, $measured],
        );

        foreach ([
            'input_tokens' => [new ResourceBudget(maxInputTokens: 2), 3, 2],
            'output_tokens' => [new ResourceBudget(maxOutputTokens: 3), 4, 3],
            'total_tokens' => [new ResourceBudget(maxTotalTokens: 6), 7, 6],
            'cost_usd' => [new ResourceBudget(maxCost: InvocationCost::fromUsd('0.19')), '0.2', '0.19'],
            'latency_milliseconds' => [new ResourceBudget(maxLatencyMilliseconds: 4), 5, 4],
        ] as $resource => [$budget, $actual, $limit]) {
            try {
                $guard->assertCurrent($this->snapshot($budget));
                self::fail("Ordered aggregation [{$resource}] was not enforced.");
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame([$resource, $actual, $limit], [$error->resource, $error->actual, $error->limit]);
            }
        }
    }

    public function test_legacy_metrics_enforce_incomplete_usage_and_known_pricing_independently(): void
    {
        $legacy = $this->invocation('legacy-incomplete');
        $legacy->start();
        $legacy->succeed(new CompletionResponse(metrics: new CompletionMetrics(
            new TokenUsage(1, 2),
            null,
            null,
            usagePresent: false,
            usageComplete: false,
        )));
        $guard = $this->guard(InvocationCost::zero(), invocations: [$legacy]);

        foreach ([
            new ResourceBudget(maxTotalTokens: 10, requireCompleteMetrics: true),
            new ResourceBudget(maxCost: InvocationCost::fromUsd('1'), requireKnownPricing: true, requireCompleteMetrics: true),
        ] as $budget) {
            try {
                $guard->assertCurrent($this->snapshot($budget));
                self::fail('Incomplete legacy metrics were accepted.');
            } catch (ResourceBudgetExceededException $error) {
                self::assertSame('complete_metrics', $error->resource);
                self::assertSame(2, $error->actual);
                self::assertSame(0, $error->limit);
            }
        }

        $pending = $this->invocation('no-response');
        $this->guard(InvocationCost::zero(), invocations: [$pending])->assertCurrent(
            $this->snapshot(new ResourceBudget(maxTotalTokens: 1, requireCompleteMetrics: true)),
        );
        self::addToAssertionCount(1);
    }

    public function test_invocation_metrics_are_used_without_attempts_and_missing_metrics_fail_closed(): void
    {
        $measured = $this->invocation('inv-fallback');
        $measured->start();
        $measured->succeed(new CompletionResponse(metrics: new CompletionMetrics(
            new TokenUsage(4, 6),
            InvocationCost::fromUsd('0.2'),
            9,
        )));
        $this->guard(InvocationCost::zero(), invocations: [$measured])->assertCurrent($this->snapshot(
            new ResourceBudget(
                maxInputTokens: 4,
                maxOutputTokens: 6,
                maxTotalTokens: 10,
                maxCost: InvocationCost::fromUsd('0.2'),
                maxLatencyMilliseconds: 9,
            ),
        ));

        $missing = $this->invocation('inv-missing');
        $missing->start();
        $missing->succeed(new CompletionResponse('answer'));
        try {
            $this->guard(InvocationCost::zero(), invocations: [$missing])->assertCurrent($this->snapshot(
                new ResourceBudget(maxTotalTokens: 100, requireCompleteMetrics: true),
            ));
            self::fail('A response without metrics was accepted.');
        } catch (ResourceBudgetExceededException $error) {
            self::assertSame('complete_metrics', $error->resource);
            self::assertSame(1, $error->actual);
            self::assertSame(0, $error->limit);
        }
    }

    public function test_reused_invocations_do_not_consume_the_recovery_run_budget(): void
    {
        $source = $this->invocation('inv-source');
        $source->start();
        $source->succeed(new CompletionResponse(metrics: new CompletionMetrics(new TokenUsage(20, 30))));
        $reused = LlmInvocation::reused(
            InvocationId::fromString('inv-reused'),
            StepExecutionId::fromString('execution-reused'),
            RunId::fromString('run-budget'),
            StepId::fromString('step-budget'),
            0,
            $this->request(),
            $source,
        );

        $this->guard(InvocationCost::zero(), invocations: [$reused])->assertCurrent($this->snapshot(
            new ResourceBudget(maxTotalTokens: 1, requireCompleteMetrics: true),
        ));
        self::addToAssertionCount(1);
    }

    /**
     * @param  list<InvocationAttempt>  $attempts
     * @param  list<LlmInvocation>  $invocations
     */
    private function guard(
        ?InvocationCost $estimate,
        ?DateTimeImmutable $now = null,
        array $attempts = [],
        array $invocations = [],
    ): ResourceBudgetGuard {
        $executions = self::createStub(ExecutionRepositoryBase::class);
        $executions->method('attemptsForRun')->willReturn($attempts);
        $executions->method('invocationsForRun')->willReturn($invocations);
        $clock = self::createStub(ClockBase::class);
        $clock->method('now')->willReturn($now ?? new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $pricing = self::createStub(PricingBase::class);
        $pricing->method('estimate')->willReturn($estimate);

        return new ResourceBudgetGuard($executions, $clock, $pricing);
    }

    private function attempt(AttemptMetrics $metrics): InvocationAttempt
    {
        $attempt = InvocationAttempt::start(
            InvocationAttemptId::fromString('attempt-budget'),
            InvocationId::fromString('inv-measured'),
            RunId::fromString('run-budget'),
            1,
            'fingerprint',
            new DateTimeImmutable('2026-08-08T09:59:59+00:00'),
        );
        $attempt->succeed(
            ProviderIdentifiers::unavailable('gateway-attempt'),
            $metrics,
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
        );

        return $attempt;
    }

    private function attemptFor(string $id, string $invocationId, AttemptMetrics $metrics): InvocationAttempt
    {
        $attempt = InvocationAttempt::start(
            InvocationAttemptId::fromString($id),
            InvocationId::fromString($invocationId),
            RunId::fromString('run-budget'),
            1,
            'fingerprint',
            new DateTimeImmutable('2026-08-08T09:59:59+00:00'),
        );
        $attempt->succeed(
            ProviderIdentifiers::unavailable("gateway-{$id}"),
            $metrics,
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
        );

        return $attempt;
    }

    private function attemptWithoutMetrics(string $id, string $invocationId): InvocationAttempt
    {
        return InvocationAttempt::start(
            InvocationAttemptId::fromString($id),
            InvocationId::fromString($invocationId),
            RunId::fromString('run-budget'),
            2,
            'fingerprint',
            new DateTimeImmutable('2026-08-08T09:59:59+00:00'),
        );
    }

    private function invocation(string $id): LlmInvocation
    {
        return LlmInvocation::pending(
            InvocationId::fromString($id),
            StepExecutionId::fromString("execution-{$id}"),
            RunId::fromString('run-budget'),
            StepId::fromString('step-budget'),
            0,
            $this->request(),
        );
    }

    private function metrics(
        TokenUsage $tokens,
        ?string $cost,
        ?int $latency,
        bool $usagePresent = true,
        bool $usageComplete = true,
    ): AttemptMetrics {
        return new AttemptMetrics(
            'provider',
            'model',
            'route',
            'standard',
            $tokens,
            $cost === null ? null : InvocationCost::fromUsd($cost),
            $latency,
            1,
            $usagePresent,
            $usageComplete,
            8,
            6,
        );
    }

    /** @param array<string, mixed> $options */
    private function request(array $options = []): CompletionRequest
    {
        return new CompletionRequest(
            [new Message('user', '12345678')],
            ResponseContract::Text,
            'budget',
            options: $options,
        );
    }

    private function snapshot(
        ResourceBudget $budget,
        ?DateTimeImmutable $startedAt = null,
    ): WorkflowRunSnapshot {
        return new WorkflowRunSnapshot(
            RunId::fromString('run-budget'),
            RunStatus::Running,
            1,
            new RunInput([]),
            'Task',
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
            resourceBudget: $budget,
            startedAt: $startedAt,
        );
    }
}
