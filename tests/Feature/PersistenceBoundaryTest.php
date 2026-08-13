<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;
use Rick\Laravel\Application\Execution\Exception\ConcurrentExecutionModificationException;
use Rick\Laravel\Application\Execution\Exception\ExecutionRecordNotFoundException;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\ContinueRunRequest;
use Rick\Laravel\Application\Execution\Request\ExecuteInvocationRequest;
use Rick\Laravel\Application\Execution\Result\ContinueRunResult;
use Rick\Laravel\Application\Execution\Result\ContinueRunStatus;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Event\InvocationRecoveryRequired;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRow;
use Rick\Laravel\Infrastructure\Persistence\Json\InteractionStateCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonRunStateCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\RunMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkflowStepCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkingMemoryCodec;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Infrastructure\Queue\Job\ExecuteInvocationJob;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class PersistenceBoundaryTest extends TestCase
{
    public function test_run_state_round_trips_through_encrypted_versioned_json(): void
    {
        $run = $this->newRun('encrypted-run');
        $repository = $this->application()->make(RunRepositoryBase::class);

        $repository->add($run);

        $payloadRow = $this->application()
            ->make(ConnectionInterface::class)
            ->table('rick_runs')
            ->where('tenant_id', 'default')
            ->where('id', 'encrypted-run')
            ->first() ?? throw new RuntimeException('Missing persisted run.');
        $payload = DatabaseRow::from($payloadRow)->string('payload');

        self::assertStringNotContainsString('encrypted-run', $payload);
        self::assertStringNotContainsString('confidential subject', $payload);

        $restored = $repository->get(RunId::fromString('encrypted-run'));

        self::assertSame('encrypted-run', $restored->id()->toString());
        self::assertSame('confidential subject', $restored->snapshot()->input->string('subject'));
        self::assertSame($run->version(), $restored->version());
    }

    public function test_repository_is_tenant_scoped(): void
    {
        $repository = $this->application()->make(RunRepositoryBase::class);
        $tenant = $this->application()->make(TenantContextBase::class);

        $tenant->run('tenant-a', function () use ($repository): void {
            $repository->add($this->newRun('shared-run'));
        });

        $this->expectException(ExecutionRecordNotFoundException::class);

        $tenant->run('tenant-b', static function () use ($repository): void {
            $repository->get(RunId::fromString('shared-run'));
        });
    }

    public function test_repository_rejects_a_stale_optimistic_version(): void
    {
        $run = $this->newRun('locked-run');
        $repository = $this->application()->make(RunRepositoryBase::class);
        $repository->add($run);

        $this->expectException(ConcurrentExecutionModificationException::class);

        $repository->save($run, 99);
    }

    public function test_run_codec_rejects_a_future_schema_version(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported run payload schema version.');

        $this->application()->make(JsonRunStateCodec::class)->decode(
            '{"schema_version":2,"run":{}}',
        );
    }

    public function test_rollback_discards_repository_state_and_after_commit_effects(): void
    {
        $repository = $this->application()->make(RunRepositoryBase::class);
        $transactions = $this->application()->make(TransactionBase::class);
        $events = $this->application()->make(EventOutboxBase::class);
        $callbackRan = false;

        try {
            $transactions->run(function () use (
                $repository,
                $transactions,
                $events,
                &$callbackRan,
            ): void {
                $run = $this->newRun('rolled-back-run');
                $repository->add($run);
                $event = $run->releaseEvents()[0] ?? throw new RuntimeException('Missing workflow event.');
                $events->record($event);
                $transactions->afterCommit(static function () use (&$callbackRan): void {
                    $callbackRan = true;
                });

                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException $error) {
            self::assertSame('Force rollback.', $error->getMessage());
        }

        self::assertFalse($callbackRan);
        self::assertSame(
            0,
            $this->application()
                ->make(ConnectionInterface::class)
                ->table('rick_runs')
                ->where('id', 'rolled-back-run')
                ->count(),
        );
        self::assertSame(
            0,
            $this->application()
                ->make(ConnectionInterface::class)
                ->table('rick_outbox')
                ->where('run_id', 'rolled-back-run')
                ->count(),
        );
    }

    public function test_memory_metrics_and_interaction_codecs_are_versioned_json(): void
    {
        $memoryCodec = $this->application()->make(WorkingMemoryCodec::class);
        $metricsCodec = $this->application()->make(RunMetricsCodec::class);
        $interactionCodec = $this->application()->make(InteractionStateCodec::class);
        $memory = new WorkingMemory(version: 2, facts: ['Fact']);
        $metrics = ['calls' => 1, 'tokens' => ['total_tokens' => 3]];
        $interaction = ['key' => 'approval', 'schema' => ['type' => 'boolean']];

        self::assertSame($memory->toArray(), $memoryCodec->decode($memoryCodec->encode($memory))->toArray());
        self::assertSame($metrics, $metricsCodec->decode($metricsCodec->encode($metrics)));
        self::assertSame(
            $interaction,
            $interactionCodec->decode($interactionCodec->encode($interaction)),
        );

        foreach ([$memoryCodec, $metricsCodec, $interactionCodec] as $codec) {
            $rejected = false;
            try {
                $codec->decode('{"schema_version":99}');
            } catch (UnexpectedValueException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'A future codec version should have been rejected.');
        }
    }

    public function test_custom_step_round_trip_requires_an_explicit_versioned_codec(): void
    {
        $codec = new class implements StepCodecBase
        {
            public function type(): StepType
            {
                return StepType::fromString('acme.publish');
            }

            public function version(): int
            {
                return 2;
            }

            public function encode(StepBase $step): array
            {
                return $step instanceof CustomPublishStep
                    ? ['channel' => $step->channel]
                    : throw new UnexpectedValueException('Unexpected custom step.');
            }

            public function decode(StepId $id, array $payload): StepBase
            {
                return new CustomPublishStep(
                    $id,
                    JsonInput::string($payload['channel'] ?? null, 'custom_step.channel'),
                );
            }
        };
        $steps = new WorkflowStepCodec([$codec]);
        $step = new CustomPublishStep(StepId::fromString('002_publish'), 'release');
        $encoded = $steps->encode($step);

        self::assertSame(1, $encoded['schema_version']);
        self::assertSame('acme.publish', $encoded['type']);
        self::assertSame(2, $encoded['codec_version']);
        self::assertSame(['channel' => 'release'], $encoded['payload']);
        self::assertEquals($step, $steps->decode($encoded));

        $encoded['codec_version'] = 3;
        $this->expectException(UnexpectedValueException::class);
        $steps->decode($encoded);
    }

    public function test_safe_gateway_failure_retries_inside_the_application_use_case(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                throw new ProviderRequestException(
                    'provider_temporarily_unavailable',
                    'Temporary provider failure.',
                    true,
                    ProviderRequestOutcome::NotAccepted,
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('retry')
            ->resolve('Generate', 'A draft is generated')
            ->generate('draft')
            ->build();

        $rick->run($workflow);

        $row = $this->application()
            ->make(ConnectionInterface::class)
            ->table('rick_llm_invocations')
            ->first();
        self::assertNotNull($row);
        $invocationRow = DatabaseRow::from($row);
        $invocation = $this->application()
            ->make(ExecutionRepositoryBase::class)
            ->getInvocation(InvocationId::fromString($invocationRow->string('id')));

        self::assertSame(InvocationStatus::Failed, $invocation->status());
        self::assertSame(3, $invocation->attempts());
        self::assertNull($invocation->leaseExpiresAt());
        self::assertStringNotContainsString('Generate', $invocationRow->string('request_payload'));
        $attempts = $this->application()
            ->make(ConnectionInterface::class)
            ->table('rick_invocation_attempts');
        self::assertSame(3, $attempts->count());
        $attempt = $attempts->orderBy('attempt_number')->first();
        self::assertNotNull($attempt);
        $attemptRow = DatabaseRow::from($attempt);
        self::assertSame('failed', $attemptRow->string('status'));
        self::assertSame(1, $attemptRow->integer('attempt_number'));
        self::assertSame(64, strlen($attemptRow->string('request_fingerprint')));
    }

    public function test_unclassified_gateway_failure_is_treated_as_an_ambiguous_paid_attempt(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                throw new RuntimeException('Unknown provider transport outcome.');
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('unclassified-attempt')
            ->resolve('Generate', 'A draft is generated')
            ->generate('draft')
            ->build();

        $rick->run($workflow);

        self::assertSame(
            'indeterminate',
            $this->application()->make(ConnectionInterface::class)
                ->table('rick_llm_invocations')
                ->value('status'),
        );
        self::assertSame(
            'provider_outcome_indeterminate',
            $this->application()->make(ConnectionInterface::class)
                ->table('rick_invocation_attempts')
                ->value('error_code'),
        );
    }

    public function test_ambiguous_paid_attempt_is_persisted_for_manual_recovery_and_never_requeued(): void
    {
        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                throw new ProviderRequestException(
                    'provider_outcome_indeterminate',
                    'The provider request outcome is unknown; operator reconciliation is required.',
                    false,
                    ProviderRequestOutcome::Indeterminate,
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('ambiguous-attempt')
            ->resolve('Generate', 'A draft is generated')
            ->generate('draft')
            ->build();

        $rick->run($workflow);

        $invocationObject = $this->application()
            ->make(ConnectionInterface::class)
            ->table('rick_llm_invocations')
            ->first();
        $attemptObject = $this->application()
            ->make(ConnectionInterface::class)
            ->table('rick_invocation_attempts')
            ->first();

        self::assertNotNull($invocationObject);
        self::assertNotNull($attemptObject);
        $invocationRow = DatabaseRow::from($invocationObject);
        $attemptRow = DatabaseRow::from($attemptObject);
        $invocationId = $invocationRow->string('id');
        $runId = $invocationRow->string('run_id');
        self::assertSame('indeterminate', $invocationRow->string('status'));
        self::assertSame('indeterminate', $attemptRow->string('status'));
        self::assertSame('provider_outcome_indeterminate', $attemptRow->string('error_code'));

        $this->application()
            ->make(Handler::class)
            ->handle(Parcel::fromArray([
                new ExecuteInvocationRequest(
                    InvocationId::fromString($invocationId),
                ),
            ]));

        self::assertSame(
            1,
            $this->application()
                ->make(ConnectionInterface::class)
                ->table('rick_invocation_attempts')
                ->count(),
        );

        Queue::fake();
        $this->artisanCommand('rick:invocation:resolve', [
            'invocation' => $invocationId,
            'outcome' => 'retry',
        ])->assertSuccessful();

        self::assertSame(
            'pending',
            $this->application()
                ->make(ConnectionInterface::class)
                ->table('rick_llm_invocations')
                ->value('status'),
        );
        Queue::assertPushed(
            ExecuteInvocationJob::class,
            static fn (ExecuteInvocationJob $job): bool => $job->invocationId === $invocationId
                && $job->runId === $runId,
        );

        $this->application()
            ->make(Handler::class)
            ->handle(Parcel::fromArray([
                new ExecuteInvocationRequest(
                    InvocationId::fromString($invocationId),
                ),
            ]));
        self::assertSame(
            'indeterminate',
            $this->application()->make(ConnectionInterface::class)
                ->table('rick_llm_invocations')
                ->value('status'),
        );
        self::assertSame(
            2,
            $this->application()->make(ConnectionInterface::class)
                ->table('rick_invocation_attempts')
                ->count(),
        );

        Queue::fake();
        $this->artisanCommand('rick:invocation:resolve', [
            'invocation' => $invocationId,
            'outcome' => 'fail',
            '--message' => 'Provider confirmed that the result cannot be recovered.',
        ])->assertSuccessful();

        self::assertSame(
            'failed',
            $this->application()
                ->make(ConnectionInterface::class)
                ->table('rick_llm_invocations')
                ->value('status'),
        );
        Queue::assertPushed(
            ContinueRunJob::class,
            static fn (ContinueRunJob $job): bool => $job->runId === $runId,
        );
    }

    public function test_execution_repository_persists_every_execution_and_attempt_field_exactly(): void
    {
        $runId = RunId::fromString('exact-repository-run');
        $stepId = StepId::fromString('001_resolve');
        $executionId = StepExecutionId::fromString('exact-execution-1');
        $invocationId = InvocationId::fromString('exact-invocation-1');
        $this->application()->make(RunRepositoryBase::class)->add($this->newRun($runId->toString()));
        $repository = $this->application()->make(ExecutionRepositoryBase::class);
        $database = $this->application()->make(ConnectionInterface::class);
        $request = new CompletionRequest(
            [new Message('user', 'Persist every invocation field')],
            ResponseContract::Text,
            'repository_exactness',
            'quality',
            ['temperature' => 0.25],
            metadata: ['trace' => 'exact'],
        );
        $execution = StepExecution::waiting(
            $executionId,
            $runId,
            $stepId,
            3,
            InvocationCompletionPolicy::minimumSuccessful(2),
        );
        $invocation = LlmInvocation::pending(
            $invocationId,
            $executionId,
            $runId,
            $stepId,
            4,
            $request,
        );

        $repository->add($execution, [$invocation]);
        $repository->add(
            StepExecution::waiting(
                StepExecutionId::fromString('exact-execution-2'),
                $runId,
                $stepId,
                1,
            ),
            [],
        );

        $executionRows = $database->table('rick_step_executions')
            ->where('run_id', $runId->toString())
            ->orderBy('sequence')
            ->get();
        self::assertCount(2, $executionRows);
        $firstExecutionRow = $executionRows[0];
        self::assertIsObject($firstExecutionRow);
        $createdExecution = DatabaseRow::from($firstExecutionRow);
        self::assertSame('exact-execution-1', $createdExecution->string('id'));
        self::assertSame('default', $createdExecution->string('tenant_id'));
        self::assertSame('exact-repository-run', $createdExecution->string('run_id'));
        self::assertSame('001_resolve', $createdExecution->string('step_id'));
        self::assertSame(1, $createdExecution->integer('sequence'));
        self::assertSame('waiting', $createdExecution->string('status'));
        self::assertSame(3, $createdExecution->integer('expected_invocations'));
        self::assertSame(0, $createdExecution->integer('dispatched_invocations'));
        self::assertSame('minimum_successful', $createdExecution->string('completion_policy'));
        self::assertSame(2, $createdExecution->integer('minimum_successful_invocations'));
        self::assertSame(0, $createdExecution->integer('version'));
        self::assertNull($createdExecution->value('error_code'));
        self::assertNull($createdExecution->value('error_message'));
        self::assertNotNull($createdExecution->value('created_at'));
        self::assertNotNull($createdExecution->value('updated_at'));
        $secondExecutionRow = $executionRows[1];
        self::assertIsObject($secondExecutionRow);
        self::assertSame(2, DatabaseRow::from($secondExecutionRow)->integer('sequence'));

        $execution->markDispatched(2);
        $execution->fail('exact_execution_failure', 'Exact execution failure message.');
        $repository->saveExecution($execution, 0);
        $savedExecution = DatabaseRow::from($database->table('rick_step_executions')
            ->where('id', $executionId->toString())
            ->first() ?? throw new RuntimeException('Missing exact execution row.'));
        self::assertSame('failed', $savedExecution->string('status'));
        self::assertSame(3, $savedExecution->integer('expected_invocations'));
        self::assertSame(2, $savedExecution->integer('dispatched_invocations'));
        self::assertSame('minimum_successful', $savedExecution->string('completion_policy'));
        self::assertSame(2, $savedExecution->integer('minimum_successful_invocations'));
        self::assertSame(2, $savedExecution->integer('version'));
        self::assertSame('exact_execution_failure', $savedExecution->string('error_code'));
        self::assertSame('Exact execution failure message.', $savedExecution->string('error_message'));
        $restoredExecution = $repository->findForStep($runId, $stepId);
        self::assertNotNull($restoredExecution);
        self::assertSame('exact-execution-2', $restoredExecution->id()->toString());

        $identifiers = new ProviderIdentifiers(
            'gateway/exact-1',
            'request-exact-1',
            'generation-exact-1',
            ProviderIdSource::Header,
        );
        $metrics = new AttemptMetrics(
            'openrouter',
            'model/exact',
            'openrouter:model/exact',
            'quality',
            new TokenUsage(101, 202, 333, 3, 4, 5),
            InvocationCost::fromUsd('0.012345678'),
            456,
            2,
            true,
            false,
            789,
            654,
        );
        $diagnostic = new StructuredResponseDiagnostic(
            StructuredResponseStage::SchemaValidation,
            ResponseContract::Candidate,
            str_repeat('a', 64),
            true,
            321,
            str_repeat('b', 64),
            StructuredDecodeStatus::Object,
            'object',
            '$.content',
            'required',
            'stop',
            true,
            false,
            'retry_scheduled',
        );
        $firstAttempt = InvocationAttempt::start(
            InvocationAttemptId::fromString('exact-attempt-1'),
            $invocationId,
            $runId,
            1,
            str_repeat('c', 64),
            new DateTimeImmutable('2026-08-08T10:11:12.000000+00:00'),
        );
        $firstAttempt->succeed(
            $identifiers,
            $metrics,
            new DateTimeImmutable('2026-08-08T10:11:13.000000+00:00'),
            $diagnostic,
        );
        $repository->addAttempt($firstAttempt);

        $firstRow = DatabaseRow::from($database->table('rick_invocation_attempts')
            ->where('id', 'exact-attempt-1')
            ->first() ?? throw new RuntimeException('Missing exact attempt row.'));
        self::assertSame('default', $firstRow->string('tenant_id'));
        self::assertSame('exact-attempt-1', $firstRow->string('id'));
        self::assertSame('exact-invocation-1', $firstRow->string('invocation_id'));
        self::assertSame('exact-repository-run', $firstRow->string('run_id'));
        self::assertSame(1, $firstRow->integer('attempt_number'));
        self::assertSame('succeeded', $firstRow->string('status'));
        self::assertSame(str_repeat('c', 64), $firstRow->string('request_fingerprint'));
        self::assertSame('gateway/exact-1', $firstRow->string('gateway_invocation_id'));
        self::assertSame('request-exact-1', $firstRow->string('provider_request_id'));
        self::assertSame('generation-exact-1', $firstRow->string('provider_generation_id'));
        self::assertSame('header', $firstRow->string('provider_id_source'));
        self::assertSame('openrouter', $firstRow->string('provider'));
        self::assertSame('model/exact', $firstRow->string('model'));
        self::assertSame('openrouter:model/exact', $firstRow->string('resolved_route'));
        self::assertSame('quality', $firstRow->string('model_tier'));
        self::assertNotNull($firstRow->value('metrics_payload'));
        self::assertNotNull($firstRow->value('diagnostic_payload'));
        self::assertSame('response_received', $firstRow->string('provider_request_outcome'));
        self::assertNull($firstRow->value('error_code'));
        self::assertNull($firstRow->value('error_message'));
        self::assertNull($firstRow->value('http_status_class'));
        self::assertSame('2026-08-08 10:11:12', $firstRow->string('started_at'));
        self::assertSame('2026-08-08 10:11:13', $firstRow->string('finished_at'));
        self::assertNotNull($firstRow->value('created_at'));
        self::assertNotNull($firstRow->value('updated_at'));

        $restoredFirst = $repository->latestAttemptFor($invocationId);
        self::assertNotNull($restoredFirst);
        self::assertEquals($identifiers, $restoredFirst->providerIdentifiers());
        self::assertEquals($metrics, $restoredFirst->metrics());
        self::assertEquals($diagnostic, $restoredFirst->diagnostic());
        self::assertSame(ProviderRequestOutcome::ResponseReceived, $restoredFirst->outcome());

        $secondAttempt = InvocationAttempt::start(
            InvocationAttemptId::fromString('exact-attempt-2'),
            $invocationId,
            $runId,
            2,
            str_repeat('d', 64),
            new DateTimeImmutable('2026-08-08T11:12:13.000000+00:00'),
        );
        $repository->addAttempt($secondAttempt);
        $secondIdentifiers = new ProviderIdentifiers(
            'gateway/exact-2',
            'request-exact-2',
            'generation-exact-2',
            ProviderIdSource::Body,
        );
        $secondAttempt->fail(
            'provider_exact_failure',
            'Exact provider failure message.',
            new DateTimeImmutable('2026-08-08T11:12:14.000000+00:00'),
            $secondIdentifiers,
            '5xx',
            $metrics,
            $diagnostic,
            ProviderRequestOutcome::NotAccepted,
        );
        $repository->saveAttempt($secondAttempt);

        $restoredSecond = $repository->latestAttemptFor($invocationId);
        self::assertNotNull($restoredSecond);
        self::assertSame('failed', $restoredSecond->status()->value);
        self::assertEquals($secondIdentifiers, $restoredSecond->providerIdentifiers());
        self::assertEquals($metrics, $restoredSecond->metrics());
        self::assertEquals($diagnostic, $restoredSecond->diagnostic());
        self::assertSame(ProviderRequestOutcome::NotAccepted, $restoredSecond->outcome());
        self::assertSame('provider_exact_failure', $restoredSecond->errorCode());
        self::assertSame('Exact provider failure message.', $restoredSecond->errorMessage());
        self::assertSame('5xx', $restoredSecond->httpStatusClass());
        self::assertSame('2026-08-08T11:12:14+00:00', $restoredSecond->finishedAt()?->format(DATE_ATOM));
        self::assertSame(
            ['exact-attempt-1', 'exact-attempt-2'],
            array_map(
                static fn (InvocationAttempt $attempt): string => $attempt->id()->toString(),
                $repository->attemptsForRun($runId),
            ),
        );
    }

    public function test_recovery_command_quarantines_expired_leases_and_publishes_an_event(): void
    {
        Queue::fake();
        Event::fake([InvocationRecoveryRequired::class]);
        $rick = $this->application()->make(Rick::class);
        $run = $rick->schedule(
            $rick->workflow('expired-lease')
                ->resolve('Generate', 'A draft is generated')
                ->generate('draft')
                ->build(),
        );
        $handler = $this->application()->make(
            Handler::class,
        );
        $transition = null;
        for ($i = 0; $i < 3; $i++) {
            $transition = $handler
                ->handle(Parcel::fromArray([
                    new ContinueRunRequest($run->id),
                ]))
                ->get(ContinueRunResult::class);
            if (
                $transition->status
                === ContinueRunStatus::Dispatch
            ) {
                break;
            }
        }
        self::assertNotNull($transition);
        self::assertNotEmpty($transition->invocations);
        $invocationId = $transition->invocations[0];
        $repository = $this->application()->make(ExecutionRepositoryBase::class);
        $invocation = $repository->getInvocation($invocationId);
        $version = $invocation->version();
        $clock = $this->application()->make(ClockBase::class);
        $startedAt = $clock->now()->sub(new DateInterval('PT2S'));
        $invocation->start($clock->now()->sub(new DateInterval('PT1S')));
        $repository->saveInvocation($invocation, $version);
        $repository->addAttempt(InvocationAttempt::start(
            InvocationAttemptId::fromString('expired-attempt'),
            $invocationId,
            $run->id,
            $invocation->attempts(),
            str_repeat('a', 64),
            $startedAt,
        ));

        $this->artisanCommand('rick:recover')->assertSuccessful();

        self::assertSame(
            InvocationStatus::Indeterminate,
            $repository->getInvocation($invocationId)->status(),
        );
        self::assertSame(
            'indeterminate',
            $repository->latestAttemptFor($invocationId)?->status()->value,
        );
        Event::assertDispatched(
            InvocationRecoveryRequired::class,
            static fn (InvocationRecoveryRequired $event): bool => $event->invocationId->toString()
                === $invocationId->toString(),
        );
    }

    private function newRun(string $id): WorkflowRun
    {
        return WorkflowRun::start(
            RunId::fromString($id),
            new CompiledWorkflow('persistence', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('001_resolve'),
                    'Persist safely',
                    DefinitionOfDone::fromString('The run survives a round trip'),
                ),
            ]),
            new RunInput(['subject' => 'confidential subject']),
            10,
        );
    }
}

final readonly class CustomPublishStep implements StepBase
{
    public function __construct(private StepId $id, public string $channel) {}

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::fromString('acme.publish');
    }
}
