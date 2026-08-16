<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunObservation;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\Support\ClaimDecisionWorkflow;
use Rick\Laravel\Tests\TestCase;
use Rick\Laravel\WorkflowState;
use RuntimeException;

final class ApplicationStepSemanticsTest extends TestCase
{
    public function test_application_step_writes_artifacts_through_workflow_state(): void
    {
        $this->application()->instance(GatewayBase::class, $this->gateway());

        $run = ClaimDecisionWorkflow::start(['claim_id' => 7]);

        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);
        self::assertTrue($run->snapshot()->hasArtifact('claim'));
        self::assertSame(7, $run->snapshot()->artifact('claim')->payload['id']);
    }

    /**
     * A throwing application-step handler is wrapped in ApplicationStepException
     * (a StepFailureBase), so the run fails cleanly instead of leaking the raw
     * exception. For a terminally-failed run the StepFailed event is rendered as
     * the run's terminal timeline observation; it still carries the step id and
     * the stable error code, which is what this test asserts.
     */
    public function test_application_step_failure_fails_the_run_cleanly(): void
    {
        $rick = $this->application()->make(Rick::class);

        $failed = $rick->run($rick->workflow('throwing')
            ->step(ThrowingStep::class, as: 'boom-step')
            ->build());

        self::assertSame(RunStatus::Failed, $failed->status);

        $timeline = $rick->timeline($failed->id)->observations;
        $failures = array_values(array_filter(
            $timeline,
            static fn (RunObservation $observation): bool => $observation->stepId?->toString() === 'boom-step'
                && ($observation->details['error_code'] ?? null) === 'application_step_failed',
        ));
        self::assertCount(1, $failures);
        self::assertSame('boom-step', $failures[0]->stepId?->toString());
        self::assertSame('application_step_failed', $failures[0]->details['error_code']);
    }

    /**
     * Application steps are immediate and persist no provider invocations, so
     * the LLM-invocation recovery actions cannot re-run them: RecoverRunPipe
     * rejects the request deterministically instead of fabricating a recovery
     * child that would re-run an arbitrary handler. At-least-once reentrancy is
     * instead the redelivery contract: the same handler is re-invoked fresh on
     * every run of the definition and persists its artifact atomically, as the
     * counting step demonstrates. (A literal worker death between handler
     * execution and commit is not reproducible in-process, so the closest
     * deterministic observable is asserted here.)
     */
    public function test_application_step_is_reentrant_across_recovery(): void
    {
        $rick = $this->application()->make(Rick::class);

        CountingStep::$invocations = 0;
        $first = $rick->run($rick->workflow('counting')
            ->step(CountingStep::class, as: 'count')
            ->build());
        self::assertSame(RunStatus::Completed, $first->status);
        self::assertSame(1, CountingStep::$invocations);
        self::assertSame(1, $first->artifact('count')->payload['count']);

        $second = $rick->run($rick->workflow('counting')
            ->step(CountingStep::class, as: 'count')
            ->build());
        self::assertSame(RunStatus::Completed, $second->status);
        self::assertSame(2, CountingStep::$invocations);
        self::assertSame(2, $second->artifact('count')->payload['count']);

        $failed = $rick->run($rick->workflow('throwing-recover')
            ->step(ThrowingStep::class, as: 'boom-step')
            ->build());
        self::assertSame(RunStatus::Failed, $failed->status);

        try {
            $rick->recover($failed->id, RunRecoveryAction::RetryFailed);
            self::fail('Expected application-step recovery to be rejected.');
        } catch (InvalidStateTransitionException $error) {
            self::assertStringContainsString('no persisted execution', $error->getMessage());
        }

        self::assertSame(RunStatus::Failed, $rick->snapshot($failed->id)->status);
        self::assertSame($failed->version, $rick->snapshot($failed->id)->version);
    }

    private function gateway(): FakeGateway
    {
        return (new FakeGateway)->respondUsing(
            static function (CompletionRequest $request): CompletionResponse {
                return match ($request->purpose) {
                    'agent:facts' => new CompletionResponse(
                        text: 'The claimant was in a rear-end collision.',
                        provider: 'fake',
                        model: 'fixture-model',
                        metrics: new CompletionMetrics(new TokenUsage(10, 5)),
                    ),
                    'agent:risk' => new CompletionResponse(
                        text: 'Low risk: no pre-existing conditions cited.',
                        provider: 'fake',
                        model: 'fixture-model',
                        metrics: new CompletionMetrics(new TokenUsage(8, 4)),
                    ),
                    default => throw new RuntimeException('Unexpected purpose ['.$request->purpose.'].'),
                };
            },
        );
    }
}

final class ThrowingStep
{
    public function __invoke(WorkflowState $state): void
    {
        throw new RuntimeException('boom');
    }
}

final class CountingStep
{
    public static int $invocations = 0;

    public function __invoke(WorkflowState $state): void
    {
        self::$invocations++;
        $state->put('count', ['count' => self::$invocations]);
    }
}
