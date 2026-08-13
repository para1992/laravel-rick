<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Metrics\RunMetricsProjection;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\InvocationStatus;
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
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class RunMetricsProjectionTest extends TestCase
{
    public function test_projection_distinguishes_clean_failed_legacy_and_sparse_attempt_metrics(): void
    {
        $clean = $this->invocation('clean', 'step-clean', 'clean', 'standard', InvocationStatus::Succeeded, 1,
            new CompletionResponse('clean response', provider: 'ignored', model: 'ignored'));
        $failed = $this->invocation('measured-failure', 'step-failed', 'failed', 'standard', InvocationStatus::Failed, 1);
        $legacy = $this->invocation('legacy-clean', 'step-legacy', 'legacy', 'standard', InvocationStatus::Succeeded, 1,
            new CompletionResponse(
                'legacy',
                provider: 'legacy-provider',
                model: 'legacy-model',
                metrics: new CompletionMetrics(
                    new TokenUsage(13, 17),
                    InvocationCost::fromUsd('0.2'),
                    7,
                    providerRequests: 1,
                ),
            ));
        $sparse = $this->invocation('sparse', 'step-sparse', 'sparse', 'premium', InvocationStatus::Succeeded, 2,
            new CompletionResponse('ignored', provider: 'response-provider', model: 'response-model'));
        $unmeasuredFailure = $this->invocation(
            'unmeasured-failure',
            'step-unmeasured-failure',
            'failed',
            'standard',
            InvocationStatus::Failed,
            2,
        );
        $unmeasuredSuccess = $this->invocation('unmeasured-success', 'step-unmeasured-success', 'plain', 'standard', InvocationStatus::Succeeded, 1,
            new CompletionResponse('plain', provider: 'plain-provider', model: 'plain-model'));

        $attempts = [
            $this->attempt('clean-attempt', 'clean', 1, $this->attemptMetrics(
                'clean-provider', 'clean-model', new TokenUsage(2, 3), '0.1', 5, 1, true, true, 10, 20,
            ), true),
            $this->attempt('failed-attempt', 'measured-failure', 1, $this->attemptMetrics(
                'failed-provider', 'failed-model', new TokenUsage(7, 11), null, null, 2, false, false, 30, 0,
            ), false),
            $this->attempt('sparse-measured', 'sparse', 1, $this->attemptMetrics(
                'sparse-provider', 'sparse-model', new TokenUsage(19, 23), '0.3', 11, 1, true, true, 40, 50,
            ), false),
            $this->attemptWithoutMetrics('sparse-unmeasured', 'sparse', 2),
        ];
        $repository = self::createStub(ExecutionRepositoryBase::class);
        $repository->method('invocationsForRun')->willReturn([
            $clean,
            $failed,
            $legacy,
            $sparse,
            $unmeasuredFailure,
            $unmeasuredSuccess,
        ]);
        $repository->method('attemptsForRun')->willReturn($attempts);

        $metrics = (new RunMetricsProjection($repository))->for($this->snapshot());

        self::assertSame([
            'schema_version' => 2,
            'calls' => 6,
            'succeeded_calls' => 4,
            'failed_calls' => 2,
            'pending_calls' => 0,
            'running_calls' => 0,
            'indeterminate_calls' => 0,
            'attempts' => 8,
            'provider_requests' => 8,
            'measured_succeeded_calls' => 3,
            'unmeasured_succeeded_calls' => 1,
            'incomplete_usage_calls' => 0,
            'unpriced_succeeded_calls' => 1,
            'prompt_characters' => 112,
            'response_characters' => 81,
            'latency_milliseconds' => 23,
            'tokens' => [
                'input_tokens' => 41,
                'output_tokens' => 54,
                'total_tokens' => 95,
                'cached_input_tokens' => 0,
                'cache_write_input_tokens' => 0,
                'reasoning_tokens' => 0,
            ],
            'cost_usd' => '0.6',
            'measured_attempts' => 3,
            'incomplete_usage_attempts' => 1,
            'unpriced_attempts' => 1,
        ], $metrics->totals->toArray());
        self::assertSame('sparse-provider', $metrics->invocations[3]->provider);
        self::assertSame('sparse-model', $metrics->invocations[3]->model);
        self::assertSame(1, $metrics->invocations[3]->providerRequests);
        self::assertTrue($metrics->invocations[3]->usagePresent);
        self::assertTrue($metrics->invocations[3]->usageComplete);
        self::assertCount(2, $metrics->invocations[3]->attemptDetails);
        self::assertNull($metrics->invocations[3]->attemptDetails[1]->metrics);
        self::assertSame(2, $metrics->invocations[4]->providerRequests);
        self::assertFalse($metrics->invocations[4]->usagePresent);
        self::assertFalse($metrics->invocations[4]->usageComplete);
        self::assertSame(1, $metrics->invocations[5]->providerRequests);
        self::assertSame(['clean', 'failed', 'legacy', 'plain', 'sparse'], array_keys($metrics->byPurpose));
        self::assertSame(2, $metrics->byPurpose['failed']->failedCalls);
    }

    public function test_projection_preserves_exact_totals_groups_attempts_and_fallback_metrics(): void
    {
        $measured = $this->invocation('measured', 'step-b', 'generate', 'premium', InvocationStatus::Succeeded, 2,
            new CompletionResponse('ignored', provider: 'response-provider', model: 'response-model'));
        $legacy = $this->invocation('legacy', 'step-a', 'judge', 'standard', InvocationStatus::Succeeded, 2,
            new CompletionResponse(
                'legacy',
                provider: 'legacy-provider',
                model: 'legacy-model',
                metrics: new CompletionMetrics(
                    new TokenUsage(5, 6),
                    InvocationCost::fromUsd('0.2'),
                    11,
                    providerRequests: 2,
                ),
            ));
        $unmeasured = $this->invocation('unmeasured', 'step-b', 'generate', 'standard', InvocationStatus::Succeeded, 1,
            new CompletionResponse('plain', provider: 'plain-provider', model: 'plain-model'));
        $failed = $this->invocation('failed', 'step-c', 'judge', 'standard', InvocationStatus::Failed, 1);
        $pending = $this->invocation('pending', 'step-c', 'wait', 'standard', InvocationStatus::Pending, 0);
        $running = $this->invocation('running', 'step-d', 'generate', 'premium', InvocationStatus::Running, 1);
        $indeterminate = $this->invocation('indeterminate', 'step-d', 'generate', 'premium', InvocationStatus::Indeterminate, 1);

        $source = $this->invocation('source', 'step-source', 'recover', 'standard', InvocationStatus::Succeeded, 1,
            new CompletionResponse('cached', provider: 'cache-provider', model: 'cache-model'));
        $reused = LlmInvocation::reused(
            InvocationId::fromString('reused'),
            StepExecutionId::fromString('execution-reused'),
            RunId::fromString('run-metrics'),
            StepId::fromString('step-e'),
            0,
            $this->request('recover', 'standard'),
            $source,
        );

        $attempts = [
            $this->attempt('attempt-1', 'measured', 1, $this->attemptMetrics(
                'provider-one', 'model-one', new TokenUsage(1, 2), null, null, 2, false, false, 10, 20,
            ), false),
            $this->attempt('attempt-2', 'measured', 2, $this->attemptMetrics(
                'provider-two', 'model-two', new TokenUsage(3, 4), '0.3', 7, 1, true, true, 30, 40,
            ), true),
            $this->attemptWithoutMetrics('attempt-ignored', 'legacy', 3),
        ];
        $invocations = [$measured, $legacy, $unmeasured, $failed, $pending, $running, $indeterminate, $reused];
        $repository = self::createStub(ExecutionRepositoryBase::class);
        $repository->method('invocationsForRun')->willReturn($invocations);
        $repository->method('attemptsForRun')->willReturn($attempts);

        $metrics = (new RunMetricsProjection($repository))->for($this->snapshot());
        $totals = $metrics->totals;

        self::assertSame('run-metrics', $metrics->runId->toString());
        self::assertSame(RunStatus::Running, $metrics->status);
        self::assertSame(7, $metrics->runVersion);
        self::assertSame(4, $metrics->callsUsed);
        self::assertSame(12, $metrics->callLimit);
        self::assertSame([
            'calls' => 8,
            'succeeded' => 4,
            'failed' => 1,
            'pending' => 1,
            'running' => 1,
            'indeterminate' => 1,
            'attempts' => 7,
            'provider_requests' => 10,
            'measured' => 2,
            'unmeasured' => 1,
            'incomplete' => 2,
            'unpriced' => 2,
            'prompt_characters' => 88,
            'response_characters' => 71,
            'latency' => 18,
            'tokens' => [9, 12, 21],
            'cost' => '0.5',
            'measured_attempts' => 2,
            'incomplete_attempts' => 1,
            'unpriced_attempts' => 1,
        ], [
            'calls' => $totals->calls,
            'succeeded' => $totals->succeededCalls,
            'failed' => $totals->failedCalls,
            'pending' => $totals->pendingCalls,
            'running' => $totals->runningCalls,
            'indeterminate' => $totals->indeterminateCalls,
            'attempts' => $totals->attempts,
            'provider_requests' => $totals->providerRequests,
            'measured' => $totals->measuredSucceededCalls,
            'unmeasured' => $totals->unmeasuredSucceededCalls,
            'incomplete' => $totals->incompleteUsageCalls,
            'unpriced' => $totals->unpricedSucceededCalls,
            'prompt_characters' => $totals->promptCharacters,
            'response_characters' => $totals->responseCharacters,
            'latency' => $totals->latencyMilliseconds,
            'tokens' => [$totals->tokens->inputTokens, $totals->tokens->outputTokens, $totals->tokens->totalTokens],
            'cost' => $totals->cost->toUsdDecimal(),
            'measured_attempts' => $totals->measuredAttempts,
            'incomplete_attempts' => $totals->incompleteUsageAttempts,
            'unpriced_attempts' => $totals->unpricedAttempts,
        ]);

        self::assertSame(['generate', 'judge', 'recover', 'wait'], array_keys($metrics->byPurpose));
        self::assertSame(['premium', 'standard'], array_keys($metrics->byModelTier));
        self::assertSame([
            'cache-provider:cache-model',
            'legacy-provider:legacy-model',
            'plain-provider:plain-model',
            'provider-two:model-two',
            'unknown:unknown',
        ], array_keys($metrics->byModel));
        self::assertSame(['step-a', 'step-b', 'step-c', 'step-d', 'step-e'], array_keys($metrics->byStep));
        self::assertSame(4, $metrics->byPurpose['generate']->calls);
        self::assertSame(2, $metrics->byStep['step-b']->succeededCalls);

        $item = $metrics->invocations[0];
        self::assertSame('provider-two', $item->provider);
        self::assertSame('model-two', $item->model);
        self::assertSame([4, 6, 10], [$item->tokens?->inputTokens, $item->tokens?->outputTokens, $item->tokens?->totalTokens]);
        self::assertSame('0.3', $item->cost?->toUsdDecimal());
        self::assertSame(7, $item->latencyMilliseconds);
        self::assertSame(3, $item->providerRequests);
        self::assertTrue($item->usagePresent);
        self::assertFalse($item->usageComplete);
        self::assertCount(2, $item->attemptDetails);
        self::assertSame(ProviderRequestOutcome::NotAccepted, $item->attemptDetails[0]->outcome);
        self::assertSame(ProviderRequestOutcome::ResponseReceived, $item->attemptDetails[1]->outcome);

        $legacyItem = $metrics->invocations[1];
        self::assertSame(2, $legacyItem->providerRequests);
        self::assertTrue($legacyItem->usagePresent);
        self::assertTrue($legacyItem->usageComplete);
        self::assertNull($metrics->invocations[2]->tokens);
        self::assertSame(1, $metrics->invocations[2]->providerRequests);
        self::assertSame('run-metrics', $metrics->invocations[7]->sourceRunId?->toString());
        self::assertSame('source', $metrics->invocations[7]->sourceInvocationId?->toString());
    }

    private function invocation(
        string $id,
        string $step,
        string $purpose,
        string $tier,
        InvocationStatus $status,
        int $attempts,
        ?CompletionResponse $response = null,
    ): LlmInvocation {
        return LlmInvocation::restore(
            InvocationId::fromString($id),
            StepExecutionId::fromString("execution-{$id}"),
            RunId::fromString('run-metrics'),
            StepId::fromString($step),
            0,
            $this->request($purpose, $tier),
            $status,
            $attempts,
            1,
            $response,
            $status === InvocationStatus::Failed ? 'failed' : null,
            null,
            metrics: $response?->metrics,
        );
    }

    private function attempt(
        string $id,
        string $invocationId,
        int $number,
        AttemptMetrics $metrics,
        bool $succeeded,
    ): InvocationAttempt {
        $attempt = InvocationAttempt::start(
            InvocationAttemptId::fromString($id),
            InvocationId::fromString($invocationId),
            RunId::fromString('run-metrics'),
            $number,
            'fingerprint',
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
        );
        if ($succeeded) {
            $attempt->succeed(
                ProviderIdentifiers::unavailable("gateway-{$id}"),
                $metrics,
                new DateTimeImmutable('2026-08-08T10:00:01+00:00'),
            );
        } else {
            $attempt->fail(
                'not_accepted',
                'Provider rejected before work.',
                new DateTimeImmutable('2026-08-08T10:00:01+00:00'),
                metrics: $metrics,
                outcome: ProviderRequestOutcome::NotAccepted,
            );
        }

        return $attempt;
    }

    private function attemptWithoutMetrics(string $id, string $invocationId, int $number): InvocationAttempt
    {
        return InvocationAttempt::restore(
            InvocationAttemptId::fromString($id),
            InvocationId::fromString($invocationId),
            RunId::fromString('run-metrics'),
            $number,
            'fingerprint',
            InvocationAttemptStatus::Failed,
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-08T10:00:01+00:00'),
            null,
            'failed',
            'failed',
        );
    }

    private function attemptMetrics(
        string $provider,
        string $model,
        TokenUsage $tokens,
        ?string $cost,
        ?int $latency,
        int $providerRequests,
        bool $usagePresent,
        bool $usageComplete,
        int $promptCharacters,
        int $responseCharacters,
    ): AttemptMetrics {
        return new AttemptMetrics(
            $provider,
            $model,
            "{$provider}:{$model}",
            'standard',
            $tokens,
            $cost === null ? null : InvocationCost::fromUsd($cost),
            $latency,
            $providerRequests,
            $usagePresent,
            $usageComplete,
            $promptCharacters,
            $responseCharacters,
        );
    }

    private function request(string $purpose, string $tier): CompletionRequest
    {
        return new CompletionRequest(
            [new Message('user', '12345678')],
            ResponseContract::Text,
            $purpose,
            $tier,
        );
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('run-metrics'),
            RunStatus::Running,
            7,
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
            4,
            12,
        );
    }
}
