<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\StepExecutionStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class ExecutionLifecycleDomainTest extends TestCase
{
    public function test_invocation_identity_and_pending_defaults_are_exact(): void
    {
        $invocation = $this->invocation();

        self::assertSame('invocation-1', $invocation->id()->toString());
        self::assertSame('execution-1', $invocation->executionId()->toString());
        self::assertSame('run-1', $invocation->runId()->toString());
        self::assertSame('step-1', $invocation->stepId()->toString());
        self::assertSame(2, $invocation->index());
        self::assertEquals($this->request(), $invocation->request());
        self::assertSame(InvocationStatus::Pending, $invocation->status());
        self::assertSame(0, $invocation->attempts());
        self::assertSame(0, $invocation->version());
        self::assertNull($invocation->response());
        self::assertNull($invocation->metrics());
        self::assertNull($invocation->errorCode());
        self::assertNull($invocation->errorMessage());
        self::assertNull($invocation->leaseExpiresAt());
        self::assertNull($invocation->sourceRunId());
        self::assertNull($invocation->sourceInvocationId());
        self::assertFalse($invocation->isReused());
    }

    public function test_start_release_retry_metrics_and_success_clear_transient_state(): void
    {
        $invocation = $this->invocation();
        $firstLease = new DateTimeImmutable('2026-08-08T11:00:00+00:00');
        $invocation->start($firstLease);
        self::assertSame(InvocationStatus::Running, $invocation->status());
        self::assertSame(1, $invocation->attempts());
        self::assertSame(1, $invocation->version());
        self::assertSame($firstLease, $invocation->leaseExpiresAt());

        $metrics = $this->metrics(1);
        $invocation->recordMetrics($metrics);
        self::assertSame($metrics, $invocation->metrics());
        self::assertSame(1, $invocation->version());

        $invocation->release('rate_limited', 'Retry later');
        self::assertSame(InvocationStatus::Pending, $invocation->status());
        self::assertSame('rate_limited', $invocation->errorCode());
        self::assertSame('Retry later', $invocation->errorMessage());
        self::assertNull($invocation->leaseExpiresAt());
        self::assertSame(2, $invocation->version());

        $invocation->start();
        self::assertNull($invocation->errorCode());
        self::assertNull($invocation->errorMessage());
        self::assertSame(2, $invocation->attempts());
        $response = new CompletionResponse('Done', metrics: $this->metrics(2));
        $invocation->succeed($response);

        self::assertSame(InvocationStatus::Succeeded, $invocation->status());
        self::assertSame($response, $invocation->response());
        self::assertSame($response->metrics, $invocation->metrics());
        self::assertNull($invocation->leaseExpiresAt());
        self::assertSame(4, $invocation->version());
    }

    public function test_failure_is_idempotent_for_every_terminal_status(): void
    {
        $failed = $this->invocation();
        $failed->fail('failed', 'Failure');
        self::assertSame(InvocationStatus::Failed, $failed->status());
        self::assertSame('failed', $failed->errorCode());
        self::assertSame('Failure', $failed->errorMessage());
        self::assertSame(1, $failed->version());
        $failed->fail('replacement', 'Replacement');
        self::assertSame('failed', $failed->errorCode());
        self::assertSame(1, $failed->version());

        $succeeded = $this->invocation();
        $succeeded->start();
        $succeeded->succeed(new CompletionResponse('Done'));
        $succeeded->fail('late', 'Late');
        self::assertSame(InvocationStatus::Succeeded, $succeeded->status());
        self::assertSame(2, $succeeded->version());

        $indeterminate = $this->indeterminate();
        $indeterminate->fail('late', 'Late');
        self::assertSame(InvocationStatus::Indeterminate, $indeterminate->status());
        self::assertSame('unknown', $indeterminate->errorCode());
        self::assertSame(2, $indeterminate->version());
    }

    public function test_indeterminate_may_be_retried_or_failed_only_by_operator(): void
    {
        $retry = $this->indeterminate();
        self::assertSame(InvocationStatus::Indeterminate, $retry->status());
        self::assertNull($retry->leaseExpiresAt());
        $retry->retryIndeterminate();
        self::assertSame(InvocationStatus::Pending, $retry->status());
        self::assertSame('manual_retry_authorized', $retry->errorCode());
        self::assertSame('An operator reconciled the provider outcome and authorized a retry.', $retry->errorMessage());
        self::assertSame(3, $retry->version());

        $failed = $this->indeterminate();
        $failed->failIndeterminate('Operator rejected the outcome.');
        self::assertSame(InvocationStatus::Failed, $failed->status());
        self::assertSame('manual_recovery_failed', $failed->errorCode());
        self::assertSame('Operator rejected the outcome.', $failed->errorMessage());
        self::assertSame(3, $failed->version());
    }

    public function test_restore_prefers_explicit_metrics_then_response_metrics(): void
    {
        $responseMetrics = $this->metrics(1);
        $explicitMetrics = $this->metrics(2);
        $response = new CompletionResponse('Done', metrics: $responseMetrics);
        $restored = $this->restore($response, $explicitMetrics);

        self::assertSame(InvocationStatus::Succeeded, $restored->status());
        self::assertSame(3, $restored->attempts());
        self::assertSame(7, $restored->version());
        self::assertSame($response, $restored->response());
        self::assertSame($explicitMetrics, $restored->metrics());
        self::assertSame('source-run', $restored->sourceRunId()?->toString());
        self::assertSame('source-invocation', $restored->sourceInvocationId()?->toString());

        self::assertSame($responseMetrics, $this->restore($response, null)->metrics());
    }

    public function test_successful_invocation_can_be_reused_without_metrics_or_attempts(): void
    {
        $source = $this->invocation();
        $source->start();
        $source->succeed(new CompletionResponse(
            text: 'Reusable',
            structured: ['answer' => 'yes'],
            provider: 'provider',
            model: 'model',
            metadata: ['original' => true],
            metrics: $this->metrics(1),
        ));
        $reused = LlmInvocation::reused(
            InvocationId::fromString('reused'),
            StepExecutionId::fromString('execution-reused'),
            RunId::fromString('run-reused'),
            StepId::fromString('step-reused'),
            0,
            $this->request(),
            $source,
        );

        self::assertTrue($reused->isReused());
        self::assertSame(InvocationStatus::Succeeded, $reused->status());
        self::assertSame(0, $reused->attempts());
        self::assertSame(0, $reused->version());
        self::assertNull($reused->metrics());
        self::assertSame('run-1', $reused->sourceRunId()?->toString());
        self::assertSame('invocation-1', $reused->sourceInvocationId()?->toString());
        self::assertSame([
            'original' => true,
            'reused_from_run_id' => 'run-1',
            'reused_from_invocation_id' => 'invocation-1',
        ], $reused->response()?->metadata);
        self::assertSame('Reusable', $reused->response()->text);
        self::assertSame(['answer' => 'yes'], $reused->response()->structured);
    }

    public function test_failed_and_undispatched_sources_are_copied_as_unavailable(): void
    {
        $failedSource = $this->invocation();
        $failedSource->fail('provider_failed', 'Provider failed');
        $failed = $this->unavailable($failedSource, 'copied-failed');
        self::assertSame(InvocationStatus::Failed, $failed->status());
        self::assertSame('provider_failed', $failed->errorCode());
        self::assertSame('Provider failed', $failed->errorMessage());
        self::assertSame('run-1', $failed->sourceRunId()?->toString());
        self::assertSame('invocation-1', $failed->sourceInvocationId()?->toString());

        $pending = $this->unavailable($this->invocation(), 'copied-pending');
        self::assertSame('recovery_source_undispatched', $pending->errorCode());
        self::assertSame('Source invocation was not dispatched before the parent run became terminal.', $pending->errorMessage());
    }

    #[DataProvider('invalidInvocationOperations')]
    public function test_invocation_rejects_invalid_construction_and_transitions(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation($this);
    }

    /** @return iterable<string, array{callable}> */
    public static function invalidInvocationOperations(): iterable
    {
        yield 'negative index' => [static fn (self $test) => $test->newInvocation(-1)];
        yield 'reuse without response' => [static fn (self $test) => $test->reuse($test->invocation())];
        yield 'reuse failed response' => [static function (self $test): void {
            $source = $test->restore(new CompletionResponse('response'), null, InvocationStatus::Failed);
            $test->reuse($source);
        }];
        yield 'unavailable succeeded' => [static function (self $test): void {
            $source = $test->invocation();
            $source->start();
            $source->succeed(new CompletionResponse('Done'));
            $test->unavailable($source, 'copy');
        }];
        yield 'unavailable indeterminate' => [static fn (self $test) => $test->unavailable($test->indeterminate(), 'copy')];
        yield 'pending source with attempt' => [static function (self $test): void {
            $source = $test->invocation();
            $source->start();
            $source->release('retry', 'Retry');
            $test->unavailable($source, 'copy');
        }];
    }

    #[DataProvider('invalidInvocationTransitions')]
    public function test_invocation_transition_guards_fail_closed(callable $operation): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        $operation($this);
    }

    /** @return iterable<string, array{callable}> */
    public static function invalidInvocationTransitions(): iterable
    {
        yield 'start running twice' => [static function (self $test): void {
            $value = $test->invocation();
            $value->start();
            $value->start();
        }];
        yield 'succeed pending' => [static fn (self $test) => $test->invocation()->succeed(new CompletionResponse('Done'))];
        yield 'release pending' => [static fn (self $test) => $test->invocation()->release('error', 'message')];
        yield 'indeterminate pending' => [static fn (self $test) => $test->invocation()->markIndeterminate('error', 'message')];
        yield 'retry pending' => [static fn (self $test) => $test->invocation()->retryIndeterminate()];
        yield 'manual fail pending' => [static fn (self $test) => $test->invocation()->failIndeterminate('message')];
        yield 'metrics pending' => [static fn (self $test) => $test->invocation()->recordMetrics($test->metrics(1))];
    }

    public function test_step_execution_runs_complete_batch_and_input_lifecycles(): void
    {
        $execution = $this->execution(2);
        self::assertSame('execution-1', $execution->id()->toString());
        self::assertSame('run-1', $execution->runId()->toString());
        self::assertSame('step-1', $execution->stepId()->toString());
        self::assertSame(2, $execution->expectedInvocations());
        self::assertSame(0, $execution->dispatchedInvocations());
        self::assertSame(InvocationCompletionPolicy::allRequired()->mode, $execution->completionPolicy()->mode);
        self::assertSame(0, $execution->version());
        self::assertNull($execution->errorCode());
        self::assertNull($execution->errorMessage());

        $execution->markDispatched(1);
        self::assertSame(1, $execution->dispatchedInvocations());
        $execution->markDispatched(1);
        self::assertSame(2, $execution->dispatchedInvocations());
        $execution->beginReduction();
        $execution->continueAfterReduction();
        $execution->awaitInput();
        self::assertSame(StepExecutionStatus::AwaitingInput, $execution->status());
        self::assertSame(0, $execution->expectedInvocations());
        self::assertSame(5, $execution->version());
        $execution->beginInputReduction();
        $execution->complete();
        self::assertSame(StepExecutionStatus::Completed, $execution->status());
        self::assertSame(7, $execution->version());
    }

    public function test_step_execution_continuation_may_complete_or_begin_fresh_batch(): void
    {
        $complete = $this->execution();
        $complete->beginReduction();
        $complete->continueAfterReduction();
        $complete->completeContinuation();
        self::assertSame(StepExecutionStatus::Completed, $complete->status());
        self::assertSame(0, $complete->expectedInvocations());
        self::assertSame(3, $complete->version());

        $next = $this->execution();
        $next->markDispatched(1);
        $next->beginReduction();
        $next->continueAfterReduction();
        $next->beginNextBatch(3);
        self::assertSame(StepExecutionStatus::Waiting, $next->status());
        self::assertSame(3, $next->expectedInvocations());
        self::assertSame(0, $next->dispatchedInvocations());
        self::assertSame(4, $next->version());
    }

    public function test_step_execution_failure_and_restore_preserve_exact_state(): void
    {
        $execution = $this->execution();
        $execution->fail('quorum_failed', 'Quorum failed');
        self::assertSame(StepExecutionStatus::Failed, $execution->status());
        self::assertSame('quorum_failed', $execution->errorCode());
        self::assertSame('Quorum failed', $execution->errorMessage());
        self::assertSame(1, $execution->version());

        $policy = InvocationCompletionPolicy::minimumSuccessful(2);
        $restored = StepExecution::restore(
            StepExecutionId::fromString('restored'),
            RunId::fromString('run-restored'),
            StepId::fromString('step-restored'),
            3,
            StepExecutionStatus::Waiting,
            8,
            'old-error',
            'Old error',
            2,
            $policy,
        );
        self::assertSame(3, $restored->expectedInvocations());
        self::assertSame(2, $restored->dispatchedInvocations());
        self::assertSame(2, $restored->completionPolicy()->required(3));
        self::assertSame(8, $restored->version());
        self::assertSame('old-error', $restored->errorCode());
        self::assertSame('Old error', $restored->errorMessage());
    }

    public function test_step_execution_can_start_at_input_barrier(): void
    {
        $execution = StepExecution::awaitingInput(
            StepExecutionId::fromString('input'),
            RunId::fromString('run-input'),
            StepId::fromString('step-input'),
        );
        self::assertSame(StepExecutionStatus::AwaitingInput, $execution->status());
        self::assertSame(0, $execution->expectedInvocations());
        self::assertSame(0, $execution->version());
    }

    #[DataProvider('invalidStepExecutionOperations')]
    public function test_step_execution_rejects_invalid_counts_and_transitions(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation($this);
    }

    /** @return iterable<string, array{callable}> */
    public static function invalidStepExecutionOperations(): iterable
    {
        yield 'waiting zero' => [static fn (self $test) => $test->execution(0)];
        yield 'waiting negative' => [static fn (self $test) => $test->execution(-1)];
        yield 'restore negative expected' => [static fn () => StepExecution::restore(StepExecutionId::fromString('id'), RunId::fromString('run'), StepId::fromString('step'), -1, StepExecutionStatus::Waiting, 0, null, null)];
        yield 'restore negative dispatched' => [static fn () => StepExecution::restore(StepExecutionId::fromString('id'), RunId::fromString('run'), StepId::fromString('step'), 1, StepExecutionStatus::Waiting, 0, null, null, -1)];
        yield 'restore too many dispatched' => [static fn () => StepExecution::restore(StepExecutionId::fromString('id'), RunId::fromString('run'), StepId::fromString('step'), 1, StepExecutionStatus::Waiting, 0, null, null, 2)];
        yield 'policy exceeds expected' => [static fn () => StepExecution::waiting(StepExecutionId::fromString('id'), RunId::fromString('run'), StepId::fromString('step'), 1, InvocationCompletionPolicy::minimumSuccessful(2))];
        yield 'next batch zero' => [static function (self $test): void {
            $value = $test->execution();
            $value->beginReduction();
            $value->continueAfterReduction();
            $value->beginNextBatch(0);
        }];
        yield 'dispatch zero' => [static fn (self $test) => $test->execution()->markDispatched(0)];
        yield 'dispatch negative' => [static fn (self $test) => $test->execution()->markDispatched(-1)];
        yield 'dispatch overflow' => [static fn (self $test) => $test->execution()->markDispatched(2)];
    }

    #[DataProvider('invalidStepTransitions')]
    public function test_step_execution_transition_guards_fail_closed(callable $operation): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        $operation($this);
    }

    /** @return iterable<string, array{callable}> */
    public static function invalidStepTransitions(): iterable
    {
        yield 'reduce input barrier' => [static fn () => StepExecution::awaitingInput(StepExecutionId::fromString('id'), RunId::fromString('run'), StepId::fromString('step'))->beginReduction()];
        yield 'await input while waiting' => [static fn (self $test) => $test->execution()->awaitInput()];
        yield 'input reduction while waiting' => [static fn (self $test) => $test->execution()->beginInputReduction()];
        yield 'complete while waiting' => [static fn (self $test) => $test->execution()->complete()];
        yield 'complete continuation while waiting' => [static fn (self $test) => $test->execution()->completeContinuation()];
        yield 'continue while waiting' => [static fn (self $test) => $test->execution()->continueAfterReduction()];
        yield 'next batch while waiting' => [static fn (self $test) => $test->execution()->beginNextBatch(1)];
        yield 'dispatch while reducing' => [static function (self $test): void {
            $value = $test->execution();
            $value->beginReduction();
            $value->markDispatched(1);
        }];
        yield 'fail completed' => [static function (self $test): void {
            $value = $test->execution();
            $value->beginReduction();
            $value->complete();
            $value->fail('late', 'Late');
        }];
    }

    private function invocation(): LlmInvocation
    {
        return $this->newInvocation(2);
    }

    private function newInvocation(int $index): LlmInvocation
    {
        return LlmInvocation::pending(
            InvocationId::fromString('invocation-1'),
            StepExecutionId::fromString('execution-1'),
            RunId::fromString('run-1'),
            StepId::fromString('step-1'),
            $index,
            $this->request(),
        );
    }

    private function indeterminate(): LlmInvocation
    {
        $invocation = $this->invocation();
        $invocation->start(new DateTimeImmutable('2026-08-08T12:00:00+00:00'));
        $invocation->markIndeterminate('unknown', 'Provider outcome is unknown');

        return $invocation;
    }

    private function restore(
        ?CompletionResponse $response,
        ?CompletionMetrics $metrics,
        InvocationStatus $status = InvocationStatus::Succeeded,
    ): LlmInvocation {
        return LlmInvocation::restore(
            InvocationId::fromString('restored'),
            StepExecutionId::fromString('execution-restored'),
            RunId::fromString('run-restored'),
            StepId::fromString('step-restored'),
            1,
            $this->request(),
            $status,
            3,
            7,
            $response,
            null,
            null,
            metrics: $metrics,
            sourceRunId: RunId::fromString('source-run'),
            sourceInvocationId: InvocationId::fromString('source-invocation'),
        );
    }

    private function reuse(LlmInvocation $source): LlmInvocation
    {
        return LlmInvocation::reused(
            InvocationId::fromString('copy'),
            StepExecutionId::fromString('execution-copy'),
            RunId::fromString('run-copy'),
            StepId::fromString('step-copy'),
            0,
            $this->request(),
            $source,
        );
    }

    private function unavailable(LlmInvocation $source, string $id): LlmInvocation
    {
        return LlmInvocation::unavailableFrom(
            InvocationId::fromString($id),
            StepExecutionId::fromString("execution-{$id}"),
            RunId::fromString("run-{$id}"),
            StepId::fromString("step-{$id}"),
            0,
            $this->request(),
            $source,
        );
    }

    private function request(): CompletionRequest
    {
        return new CompletionRequest(
            [new Message('user', 'Do the work')],
            ResponseContract::Text,
            'operation',
        );
    }

    private function metrics(int $multiplier): CompletionMetrics
    {
        return new CompletionMetrics(
            new TokenUsage(10 * $multiplier, 5 * $multiplier, 15 * $multiplier),
            new InvocationCost(100 * $multiplier),
            20 * $multiplier,
            ['route' => "route-{$multiplier}"],
        );
    }

    private function execution(int $expected = 1): StepExecution
    {
        return StepExecution::waiting(
            StepExecutionId::fromString('execution-1'),
            RunId::fromString('run-1'),
            StepId::fromString('step-1'),
            $expected,
        );
    }
}
