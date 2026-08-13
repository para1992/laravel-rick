<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Event\CandidateReviewRequested;
use Rick\Laravel\Domain\Event\ExternalInputRequested;
use Rick\Laravel\Domain\Event\InvocationRecoveryRequired;
use Rick\Laravel\Domain\Event\LlmCallReserved;
use Rick\Laravel\Domain\Event\MemoryCommitted;
use Rick\Laravel\Domain\Event\StepCompleted;
use Rick\Laravel\Domain\Event\StepContinued;
use Rick\Laravel\Domain\Event\StepDegraded;
use Rick\Laravel\Domain\Event\StepFailed;
use Rick\Laravel\Domain\Event\StepStarted;
use Rick\Laravel\Domain\Event\UsageRecorded;
use Rick\Laravel\Domain\Event\WorkflowCompleted;
use Rick\Laravel\Domain\Event\WorkflowCreated;
use Rick\Laravel\Domain\Event\WorkflowRecoveryStarted;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Infrastructure\Persistence\DatabaseExecutionReadModel;
use Rick\Laravel\Infrastructure\Persistence\Json\AttemptMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionRequestCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\StructuredResponseDiagnosticCodec;
use UnexpectedValueException;

final class DatabaseExecutionReadModelTest extends TestCase
{
    public function test_event_projection_helpers_preserve_exact_safe_details_and_identifiers(): void
    {
        $runId = RunId::fromString('run-read-model');
        $parentRunId = RunId::fromString('parent-read-model');
        $stepId = StepId::fromString('step-read-model');
        $candidateId = CandidateId::fromString('candidate-read-model');
        $invocationId = InvocationId::fromString('invocation-read-model');
        $time = new DateTimeImmutable('2026-08-08T12:34:56.123456+00:00');
        $cases = [
            [new WorkflowCreated($runId, 'Workflow', '2', $time), [
                'event_type' => 'workflow.created',
                'workflow_name' => 'Workflow',
                'workflow_version' => '2',
            ], null, null],
            [new WorkflowRecoveryStarted(
                $runId,
                $parentRunId,
                RunRecoveryAction::ContinueSuccessful,
                $stepId,
                $time,
            ), [
                'event_type' => 'workflow.recovery_started',
                'parent_run_id' => 'parent-read-model',
                'action' => 'continue_successful',
                'step_id' => 'step-read-model',
            ], null, null],
            [new StepStarted($runId, $stepId, StepType::fromString('generate'), $time), [
                'event_type' => 'step.started',
                'step_type' => 'generate',
            ], $stepId, null],
            [new LlmCallReserved($runId, 3, 8, 'generate_candidate', $time), [
                'event_type' => 'llm.call_reserved',
                'call' => 3,
                'limit' => 8,
            ], null, null],
            [new StepCompleted($runId, $stepId, ['safe' => true], $time), [
                'event_type' => 'step.completed',
            ], $stepId, null],
            [new StepContinued($runId, $stepId, ['safe' => true], $time), [
                'event_type' => 'step.continued',
            ], $stepId, null],
            [new StepDegraded($runId, $stepId, 7, 4, ['timeout', 'invalid'], $time), [
                'event_type' => 'step.degraded',
                'expected' => 7,
                'succeeded' => 4,
                'failed' => 3,
                'failure_codes' => ['timeout', 'invalid'],
            ], $stepId, null],
            [new StepFailed($runId, $stepId, 'safe_code', 'Safe message', $time), [
                'event_type' => 'step.failed',
                'error_code' => 'safe_code',
            ], $stepId, null],
            [new WorkflowCompleted($runId, 'Output', $time), [
                'event_type' => 'workflow.completed',
            ], null, null],
            [new CandidateReviewRequested($runId, $stepId, 'plan', [$candidateId], [], $time), [
                'event_type' => 'candidate.review_requested',
                'scope' => 'plan',
                'candidate_count' => 1,
            ], $stepId, null],
            [new ExternalInputRequested($runId, $stepId, 'approval', 'Approve?', null, $time), [
                'event_type' => 'external_input.requested',
                'input_key' => 'approval',
            ], $stepId, null],
            [new MemoryCommitted(
                $runId,
                $stepId,
                $candidateId,
                'unit',
                2,
                str_repeat('a', 64),
                $time,
            ), [
                'event_type' => 'memory.committed',
            ], $stepId, null],
            [new UsageRecorded(
                $runId,
                $stepId,
                $invocationId,
                'judge',
                'quality',
                'provider',
                'model',
                new TokenUsage(1, 2),
                InvocationCost::fromUsd('0.001'),
                12,
                4,
                true,
                $time,
            ), [
                'event_type' => 'usage.recorded',
                'provider' => 'provider',
                'model' => 'model',
                'provider_requests' => 4,
            ], $stepId, $invocationId],
            [new InvocationRecoveryRequired($runId, $invocationId, 'expired', $time), [
                'event_type' => 'invocation.recovery_required',
                'reason' => 'expired',
            ], null, $invocationId],
        ];

        foreach ($cases as [$event, $details, $expectedStepId, $expectedInvocationId]) {
            self::assertEquals($time, self::invoke('eventTime', $event));
            self::assertEquals($expectedStepId, self::invoke('eventStepId', $event));
            self::assertEquals($expectedInvocationId, self::invoke('eventInvocationId', $event));
            self::assertSame($details, self::invoke('eventDetails', $event, $details['event_type']));
        }
    }

    public function test_raw_invocation_and_delivery_helpers_preserve_every_field(): void
    {
        $stepId = StepId::fromString('step-raw');
        $invocationId = InvocationId::fromString('invocation-raw');
        $attemptId = InvocationAttemptId::fromString('attempt-raw');
        $time = new DateTimeImmutable('2026-08-08T10:00:00+00:00');

        self::assertSame([
            'id' => 'observation',
            'type' => 'invocation.succeeded',
            'occurred_at' => $time,
            'step_id' => $stepId,
            'invocation_id' => $invocationId,
            'attempt' => 3,
            'attempt_id' => $attemptId,
            'operation_key' => 'judge',
            'details' => ['candidate_number' => 2],
        ], self::invoke(
            'raw',
            'observation',
            'invocation.succeeded',
            $time,
            $stepId,
            $invocationId,
            3,
            $attemptId,
            'judge',
            ['candidate_number' => 2],
        ));

        self::assertNull(self::invoke('invocationId', null));
        self::assertEquals($invocationId, self::invoke('invocationId', 'invocation-raw'));
        self::assertSame('pending', self::invoke('deliveryStatus', 'pending'));
        self::assertSame('claimed', self::invoke('deliveryStatus', 'delivering'));
        self::assertSame('delivered', self::invoke('deliveryStatus', 'delivered'));
        self::assertSame('quarantined', self::invoke('deliveryStatus', 'failed'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported outbox status [unknown].');
        self::invoke('deliveryStatus', 'unknown');
    }

    public function test_cursor_is_canonical_query_bound_and_strictly_validated(): void
    {
        $time = new DateTimeImmutable('2026-08-08T10:20:30+00:00');
        $runId = RunId::fromString('run-cursor');
        $cursor = self::invoke('encodeCursor', $time, $runId, 'tenant-a', RunStatus::Running);
        $expectedJson = json_encode([
            'schema_version' => 1,
            'updated_at' => '2026-08-08T10:20:30+00:00',
            'id' => 'run-cursor',
            'tenant_hash' => hash('sha256', 'tenant-a'),
            'status' => 'running',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertSame(rtrim(strtr(base64_encode($expectedJson), '+/', '-_'), '='), $cursor);
        self::assertEquals([
            'updated_at' => $time,
            'id' => 'run-cursor',
        ], self::invoke('decodeCursor', $cursor, 'tenant-a', RunStatus::Running));

        $unfiltered = self::invoke('encodeCursor', $time, $runId, 'tenant-a', null);
        $decodedUnfiltered = self::invoke('decodeCursor', $unfiltered, 'tenant-a', null);
        self::assertIsArray($decodedUnfiltered);
        self::assertSame('run-cursor', $decodedUnfiltered['id']);

        $invalid = [
            '',
            '*',
            self::cursorPayload(null),
            self::cursorPayload([]),
            self::cursorPayload(['schema_version' => 2]),
            self::cursorPayload(self::cursorData(updatedAt: 1)),
            self::cursorPayload(self::cursorData(id: 1)),
            self::cursorPayload(self::cursorData(tenantHash: 1)),
            self::cursorPayload(self::cursorData(status: 1)),
            self::cursorPayload(self::cursorData(updatedAt: 'not-a-date')),
            self::cursorPayload(self::cursorData(id: '')),
        ];
        foreach ($invalid as $value) {
            try {
                self::invoke('decodeCursor', $value, 'tenant-a', RunStatus::Running);
                self::fail('Invalid cursor was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([
            ['tenant-b', RunStatus::Running],
            ['tenant-a', RunStatus::Failed],
            ['tenant-a', null],
        ] as [$tenant, $status]) {
            try {
                self::invoke('decodeCursor', $cursor, $tenant, $status);
                self::fail('Cursor escaped its active query binding.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Run page cursor does not match the active query.', $error->getMessage());
            }
        }
    }

    public function test_event_projection_orders_events_and_resolves_each_manual_barrier_fifo(): void
    {
        $runId = RunId::fromString('run-barriers');
        $stepId = StepId::fromString('step-barriers');
        $candidateId = CandidateId::fromString('candidate-barriers');
        $events = [
            ['id' => 'complete-second', 'event' => new StepCompleted(
                $runId,
                $stepId,
                [],
                new DateTimeImmutable('2026-08-08T10:00:06+00:00'),
            ), 'logical_type' => 'step.completed'],
            ['id' => 'input-second', 'event' => new ExternalInputRequested(
                $runId,
                $stepId,
                'approval-2',
                'Approve second?',
                null,
                new DateTimeImmutable('2026-08-08T10:00:04+00:00'),
            ), 'logical_type' => 'external_input.requested'],
            ['id' => 'review-first', 'event' => new CandidateReviewRequested(
                $runId,
                $stepId,
                'first',
                [$candidateId],
                [],
                new DateTimeImmutable('2026-08-08T10:00:01+00:00'),
            ), 'logical_type' => 'candidate.review_requested'],
            ['id' => 'continue-first', 'event' => new StepContinued(
                $runId,
                $stepId,
                [],
                new DateTimeImmutable('2026-08-08T10:00:05+00:00'),
            ), 'logical_type' => 'step.continued'],
            ['id' => 'review-second', 'event' => new CandidateReviewRequested(
                $runId,
                $stepId,
                'second',
                [$candidateId],
                [],
                new DateTimeImmutable('2026-08-08T10:00:02+00:00'),
            ), 'logical_type' => 'candidate.review_requested'],
            ['id' => 'input-first', 'event' => new ExternalInputRequested(
                $runId,
                $stepId,
                'approval-1',
                'Approve first?',
                null,
                new DateTimeImmutable('2026-08-08T10:00:03+00:00'),
            ), 'logical_type' => 'external_input.requested'],
        ];

        $projected = self::invokeOn($this->readModel(), 'eventObservations', $events, 'running');
        self::assertIsArray($projected);
        self::assertSame([
            'review-first',
            'review-second',
            'input-first',
            'input-second',
            'continue-first',
            hash('sha256', "manual.review.resolved\0review-first\0continue-first"),
            hash('sha256', "manual.input.resolved\0input-first\0continue-first"),
            'complete-second',
            hash('sha256', "manual.review.resolved\0review-second\0complete-second"),
            hash('sha256', "manual.input.resolved\0input-second\0complete-second"),
        ], array_column($projected, 'id'));
        self::assertSame([
            'manual.review.opened',
            'manual.review.opened',
            'manual.input.opened',
            'manual.input.opened',
            'run.continued',
            'manual.review.resolved',
            'manual.input.resolved',
            'domain.step.completed',
            'manual.review.resolved',
            'manual.input.resolved',
        ], array_column($projected, 'type'));
        self::assertSame([
            ['event_type' => 'candidate.review_requested', 'scope' => 'first', 'candidate_count' => 1],
            ['event_type' => 'candidate.review_requested', 'scope' => 'second', 'candidate_count' => 1],
            ['event_type' => 'external_input.requested', 'input_key' => 'approval-1'],
            ['event_type' => 'external_input.requested', 'input_key' => 'approval-2'],
            ['event_type' => 'step.continued'],
            [],
            [],
            ['event_type' => 'step.completed'],
            [],
            [],
        ], array_column($projected, 'details'));
        foreach ($projected as $projection) {
            self::assertIsArray($projection);
            self::assertEquals($stepId, $projection['step_id']);
            self::assertNull($projection['invocation_id']);
            self::assertNull($projection['attempt']);
            self::assertNull($projection['attempt_id']);
            self::assertNull($projection['operation_key']);
        }
        $times = [];
        foreach ($projected as $projection) {
            self::assertIsArray($projection);
            self::assertInstanceOf(DateTimeImmutable::class, $projection['occurred_at']);
            $times[] = $projection['occurred_at']->format('H:i:s');
        }
        self::assertSame(
            ['10:00:01', '10:00:02', '10:00:03', '10:00:04', '10:00:05', '10:00:05', '10:00:05', '10:00:06', '10:00:06', '10:00:06'],
            $times,
        );
    }

    /** @return array<string, mixed> */
    private static function cursorData(
        mixed $updatedAt = '2026-08-08T10:20:30+00:00',
        mixed $id = 'run-cursor',
        mixed $tenantHash = null,
        mixed $status = 'running',
    ): array {
        return [
            'schema_version' => 1,
            'updated_at' => $updatedAt,
            'id' => $id,
            'tenant_hash' => $tenantHash ?? hash('sha256', 'tenant-a'),
            'status' => $status,
        ];
    }

    private static function cursorPayload(mixed $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(DatabaseExecutionReadModel::class, $method))->invoke(null, ...$arguments);
    }

    private static function invokeOn(
        DatabaseExecutionReadModel $readModel,
        string $method,
        mixed ...$arguments,
    ): mixed {
        return (new ReflectionMethod(DatabaseExecutionReadModel::class, $method))->invoke($readModel, ...$arguments);
    }

    private function readModel(): DatabaseExecutionReadModel
    {
        return new DatabaseExecutionReadModel(
            self::createStub(ConnectionInterface::class),
            new CompletionRequestCodec,
            new DomainEventCodec,
            new AttemptMetricsCodec,
            new StructuredResponseDiagnosticCodec,
            self::createStub(PayloadProtectorBase::class),
            self::createStub(TenantContextBase::class),
        );
    }
}
