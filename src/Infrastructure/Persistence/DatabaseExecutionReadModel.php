<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use Rick\Laravel\Application\Execution\Exception\ExecutionRecordNotFoundException;
use Rick\Laravel\Application\Execution\Interface\ExecutionReadModelBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Event\CandidateReviewRequested;
use Rick\Laravel\Domain\Event\ExternalInputRequested;
use Rick\Laravel\Domain\Event\Interface\EventBase;
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
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\DeliveryRecord;
use Rick\Laravel\Domain\Run\DeliverySnapshot;
use Rick\Laravel\Domain\Run\RunObservation;
use Rick\Laravel\Domain\Run\RunPage;
use Rick\Laravel\Domain\Run\RunRecovery;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\RunSummary;
use Rick\Laravel\Domain\Run\RunTimeline;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Persistence\Json\AttemptMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionRequestCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\StructuredResponseDiagnosticCodec;
use Throwable;
use UnexpectedValueException;

final readonly class DatabaseExecutionReadModel implements ExecutionReadModelBase
{
    public function __construct(
        private ConnectionInterface $database,
        private CompletionRequestCodec $requests,
        private DomainEventCodec $events,
        private AttemptMetricsCodec $attemptMetrics,
        private StructuredResponseDiagnosticCodec $diagnostics,
        private PayloadProtectorBase $payloads,
        private TenantContextBase $tenant,
        private string $runsTable = 'rick_runs',
        private string $invocationsTable = 'rick_llm_invocations',
        private string $attemptsTable = 'rick_invocation_attempts',
        private string $outboxTable = 'rick_outbox',
        private string $observationsTable = 'rick_run_observations',
    ) {}

    public function runs(?string $cursor, ?RunStatus $status, int $limit): RunPage
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Run page limit must be between 1 and 100.');
        }

        $query = $this->database->table($this->runsTable)
            ->where('tenant_id', $this->tenant->id());
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($cursor !== null) {
            $position = self::decodeCursor($cursor, $this->tenant->id(), $status);
            $query->where(function (Builder $page) use ($position): void {
                $page
                    ->where('updated_at', '<', $position['updated_at'])
                    ->orWhere(function (Builder $sameTimestamp) use ($position): void {
                        $sameTimestamp
                            ->where('updated_at', '=', $position['updated_at'])
                            ->where('id', '<', $position['id']);
                    });
            });
        }

        $rows = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $runs = array_map(static function (object $row): RunSummary {
            $data = DatabaseRow::from($row);

            return new RunSummary(
                RunId::fromString($data->string('id')),
                RunStatus::from($data->string('status')),
                $data->integer('version'),
                $data->timestamp('created_at'),
                $data->timestamp('updated_at'),
                ($parentRunId = $data->nullableStringOr('parent_run_id')) === null
                    ? null
                    : new RunRecovery(
                        RunId::fromString($parentRunId),
                        RunRecoveryAction::from($data->string('recovery_action')),
                        StepId::fromString($data->string('recovery_step_id')),
                    ),
            );
        }, array_values($rows));
        $last = $runs === [] ? null : $runs[array_key_last($runs)];

        return new RunPage(
            $runs,
            $hasMore && $last instanceof RunSummary
                ? self::encodeCursor($last->updatedAt, $last->id, $this->tenant->id(), $status)
                : null,
        );
    }

    public function timeline(RunId $runId, int $afterVersion): RunTimeline
    {
        if ($afterVersion < 0) {
            throw new InvalidArgumentException('Timeline version must not be negative.');
        }
        $run = $this->runRow($runId);
        $raw = [self::raw(
            hash('sha256', "run.persisted\0".$runId->toString()),
            'run.persisted',
            $run->timestamp('created_at'),
        )];

        $outboxRows = $this->database->table($this->outboxTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
        $domainEvents = [];

        foreach ($outboxRows as $row) {
            $data = DatabaseRow::from($row);
            $deduplicationKey = $data->string('deduplication_key');
            $kind = $data->string('kind');
            $createdAt = $data->timestamp('created_at');
            $invocationId = self::invocationId($data->nullableString('invocation_id'));
            $raw[] = self::raw(
                hash('sha256', "outbox.pending\0{$deduplicationKey}"),
                'outbox.pending',
                $createdAt,
                invocationId: $invocationId,
                details: ['kind' => $kind],
            );

            if ($kind === 'continue_run') {
                $raw[] = self::raw(
                    hash('sha256', "run.continued\0{$deduplicationKey}"),
                    'run.continued',
                    $createdAt,
                    invocationId: $invocationId,
                    details: ['source' => 'queue_intent'],
                );
            } elseif ($kind === 'execute_invocation' && $invocationId !== null) {
                $raw[] = self::raw(
                    hash('sha256', "invocation.queued\0{$deduplicationKey}"),
                    'invocation.queued',
                    $createdAt,
                    invocationId: $invocationId,
                );
            } elseif ($kind === 'domain_event') {
                $eventType = $data->nullableString('event_type');
                $payload = $data->nullableString('payload');
                if ($eventType === null || $payload === null) {
                    throw new UnexpectedValueException('Domain-event outbox record is incomplete.');
                }
                $domainEvents[] = [
                    'id' => $deduplicationKey,
                    'event' => $this->events->decode(
                        $eventType,
                        $this->payloads->reveal($payload),
                    ),
                    'logical_type' => $eventType,
                ];
            }

            $attempts = $data->integer('attempts');
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $raw[] = self::raw(
                    hash('sha256', "outbox.claimed\0{$deduplicationKey}\0{$attempt}"),
                    'outbox.claimed',
                    $data->nullableTimestamp('delivered_at') ?? $data->timestamp('updated_at'),
                    invocationId: $invocationId,
                    details: [
                        'kind' => $kind,
                        'attempt' => $attempt,
                    ],
                );
            }

            $deliveryStatus = self::deliveryStatus($data->string('status'));
            if (in_array($deliveryStatus, ['delivered', 'quarantined'], true)) {
                $raw[] = self::raw(
                    hash('sha256', "outbox.{$deliveryStatus}\0{$deduplicationKey}"),
                    'outbox.'.$deliveryStatus,
                    $data->nullableTimestamp('delivered_at') ?? $data->timestamp('updated_at'),
                    invocationId: $invocationId,
                    details: [
                        'kind' => $kind,
                        'attempts' => $attempts,
                        'error_code' => $data->nullableString('last_error_code'),
                    ],
                );
            }
        }

        $raw = [...$raw, ...$this->eventObservations($domainEvents, $run->string('status'))];
        $raw = [...$raw, ...$this->invocationObservations($runId)];
        usort($raw, static fn (array $left, array $right): int => [
            $left['occurred_at']->format('U.u'),
            $left['id'],
        ] <=> [
            $right['occurred_at']->format('U.u'),
            $right['id'],
        ]);

        $seen = [];
        $unique = [];
        foreach ($raw as $item) {
            if (isset($seen[$item['id']])) {
                continue;
            }
            $seen[$item['id']] = true;
            $unique[] = $item;
        }
        $versions = $this->observationVersions($runId, array_keys($seen));
        $observations = [];
        foreach ($unique as $item) {
            $observations[] = new RunObservation(
                $item['id'],
                $item['type'],
                $versions[$item['id']],
                $item['occurred_at'],
                $item['step_id'],
                $item['invocation_id'],
                $item['attempt'],
                $item['operation_key'],
                $item['details'],
                $item['attempt_id'],
            );
        }
        usort(
            $observations,
            static fn (RunObservation $left, RunObservation $right): int => $left->version <=> $right->version,
        );
        $latestVersion = $observations[array_key_last($observations)]->version;

        return new RunTimeline(
            $runId,
            $run->integer('version'),
            $latestVersion,
            array_values(array_filter(
                $observations,
                static fn (RunObservation $observation): bool => $observation->version > $afterVersion,
            )),
        );
    }

    public function delivery(RunId $runId): DeliverySnapshot
    {
        $this->runRow($runId);
        $counts = ['pending' => 0, 'claimed' => 0, 'delivered' => 0, 'quarantined' => 0];
        $records = [];
        $rows = $this->database->table($this->outboxTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $data = DatabaseRow::from($row);
            $status = self::deliveryStatus($data->string('status'));
            switch ($status) {
                case 'pending':
                    $counts['pending']++;
                    break;
                case 'claimed':
                    $counts['claimed']++;
                    break;
                case 'delivered':
                    $counts['delivered']++;
                    break;
                case 'quarantined':
                    $counts['quarantined']++;
                    break;
            }
            $records[] = new DeliveryRecord(
                $data->string('id'),
                $data->string('deduplication_key'),
                $data->string('kind'),
                $status,
                $data->integer('attempts'),
                $data->timestamp('available_at'),
                $data->timestamp('created_at'),
                $data->timestamp('updated_at'),
                self::invocationId($data->nullableString('invocation_id')),
                $data->nullableString('event_type'),
                $data->nullableTimestamp('lease_expires_at'),
                $data->nullableTimestamp('delivered_at'),
                $data->nullableString('last_error_code'),
            );
        }

        return new DeliverySnapshot($runId, $counts, $records);
    }

    /**
     * @param  list<array{id: string, event: EventBase, logical_type: string}>  $events
     * @return list<array{id: string, type: string, occurred_at: DateTimeImmutable, step_id: ?StepId, invocation_id: ?InvocationId, attempt: ?int, attempt_id: ?InvocationAttemptId, operation_key: ?string, details: array<string, mixed>}>
     */
    private function eventObservations(array $events, string $runStatus): array
    {
        usort($events, static fn (array $left, array $right): int => [
            self::eventTime($left['event'])->format('U.u'),
            $left['id'],
        ] <=> [
            self::eventTime($right['event'])->format('U.u'),
            $right['id'],
        ]);
        $raw = [];
        /** @var array<string, array<string, list<string>>> $barriers */
        $barriers = [];

        foreach ($events as $entry) {
            $event = $entry['event'];
            $logicalType = $entry['logical_type'];
            $stepId = self::eventStepId($event);
            $invocationId = self::eventInvocationId($event);
            $type = match ($event::class) {
                WorkflowCreated::class => 'run.scheduled',
                WorkflowRecoveryStarted::class => 'run.recovery.started',
                WorkflowCompleted::class => 'run.terminal',
                StepContinued::class => 'run.continued',
                StepDegraded::class => 'step.degraded',
                StepFailed::class => $runStatus === RunStatus::Failed->value
                    ? 'run.terminal'
                    : 'step.failed',
                CandidateReviewRequested::class => 'manual.review.opened',
                ExternalInputRequested::class => 'manual.input.opened',
                InvocationRecoveryRequired::class => 'invocation.indeterminate',
                UsageRecorded::class => 'invocation.usage_recorded',
                default => 'domain.'.$logicalType,
            };
            $raw[] = self::raw(
                $entry['id'],
                $type,
                self::eventTime($event),
                $stepId,
                $invocationId,
                operationKey: $event instanceof LlmCallReserved || $event instanceof UsageRecorded
                    ? $event->purpose
                    : null,
                details: self::eventDetails($event, $logicalType),
            );

            if ($event instanceof CandidateReviewRequested && $stepId !== null) {
                $barriers[$stepId->toString()]['review'][] = $entry['id'];
            } elseif ($event instanceof ExternalInputRequested && $stepId !== null) {
                $barriers[$stepId->toString()]['input'][] = $entry['id'];
            } elseif (($event instanceof StepContinued || $event instanceof StepCompleted) && $stepId !== null) {
                foreach (['review', 'input'] as $kind) {
                    if (($barriers[$stepId->toString()][$kind] ?? []) === []) {
                        continue;
                    }
                    $openedId = array_shift($barriers[$stepId->toString()][$kind]);
                    $raw[] = self::raw(
                        hash('sha256', implode("\0", [
                            'manual.'.$kind.'.resolved',
                            $openedId,
                            $entry['id'],
                        ])),
                        'manual.'.$kind.'.resolved',
                        self::eventTime($event),
                        $stepId,
                    );
                }
            }
        }

        return $raw;
    }

    /**
     * @return list<array{id: string, type: string, occurred_at: DateTimeImmutable, step_id: ?StepId, invocation_id: ?InvocationId, attempt: ?int, attempt_id: ?InvocationAttemptId, operation_key: ?string, details: array<string, mixed>}>
     */
    private function invocationObservations(RunId $runId): array
    {
        $raw = [];
        /** @var array<string, array{step_id: StepId, step_execution_id: string, index: int, operation_key: string}> $contexts */
        $contexts = [];
        $rows = $this->database->table($this->invocationsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->orderBy('created_at')
            ->orderBy('invocation_index')
            ->get();
        foreach ($rows as $row) {
            $data = DatabaseRow::from($row);
            $id = InvocationId::fromString($data->string('id'));
            $stepId = StepId::fromString($data->string('step_id'));
            $request = $this->requests->decode(
                $this->payloads->reveal($data->string('request_payload')),
            );
            $index = $data->integer('invocation_index');
            $executionId = $data->string('step_execution_id');
            $contexts[$id->toString()] = [
                'step_id' => $stepId,
                'step_execution_id' => $executionId,
                'index' => $index,
                'operation_key' => $request->purpose,
            ];
            $sourceRunId = $data->nullableStringOr('source_run_id');
            $sourceInvocationId = $data->nullableStringOr('source_invocation_id');
            $provenance = [
                'source_run_id' => $sourceRunId,
                'source_invocation_id' => $sourceInvocationId,
                'reused' => $sourceRunId !== null
                    && $sourceInvocationId !== null
                    && $data->integer('attempts') === 0
                    && $data->string('status') === InvocationStatus::Succeeded->value,
            ];
            $raw[] = self::raw(
                hash('sha256', "invocation.planned\0".$id->toString()),
                'invocation.planned',
                $data->timestamp('created_at'),
                $stepId,
                $id,
                operationKey: $request->purpose,
                details: [
                    'step_execution_id' => $executionId,
                    'original_index' => $index,
                    'candidate_number' => $index + 1,
                ] + $provenance,
            );
            $status = InvocationStatus::from($data->string('status'));
            if (in_array($status, [InvocationStatus::Succeeded, InvocationStatus::Failed], true)) {
                $raw[] = self::raw(
                    hash('sha256', implode("\0", [
                        'invocation.current',
                        $id->toString(),
                        $status->value,
                        (string) $data->integer('version'),
                    ])),
                    'invocation.'.$status->value,
                    $data->timestamp('updated_at'),
                    $stepId,
                    $id,
                    operationKey: $request->purpose,
                    details: [
                        'step_execution_id' => $executionId,
                        'original_index' => $index,
                        'candidate_number' => $index + 1,
                        'error_code' => $data->nullableString('error_code'),
                        'terminal_timestamp' => $data->timestamp('updated_at')->format(DATE_ATOM),
                    ] + $provenance,
                );
            }
        }

        $attemptRows = $this->database->table($this->attemptsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
        foreach ($attemptRows as $row) {
            $data = DatabaseRow::from($row);
            $invocationId = InvocationId::fromString($data->string('invocation_id'));
            $context = $contexts[$invocationId->toString()] ?? null;
            if ($context === null) {
                throw new UnexpectedValueException('Invocation attempt has no parent invocation context.');
            }
            $attemptId = InvocationAttemptId::fromString($data->string('id'));
            $number = $data->integer('attempt_number');
            $raw[] = self::raw(
                hash('sha256', implode("\0", [
                    'invocation.leased',
                    $invocationId->toString(),
                    (string) $number,
                ])),
                'invocation.leased',
                $data->timestamp('started_at'),
                $context['step_id'],
                invocationId: $invocationId,
                attempt: $number,
                attemptId: $attemptId,
                operationKey: $context['operation_key'],
                details: [
                    'step_execution_id' => $context['step_execution_id'],
                    'original_index' => $context['index'],
                    'candidate_number' => $context['index'] + 1,
                ],
            );
            $status = InvocationAttemptStatus::from($data->string('status'));
            $finishedAt = $data->nullableTimestamp('finished_at');
            if ($status === InvocationAttemptStatus::Running || $finishedAt === null) {
                continue;
            }
            $metricsPayload = $data->nullableString('metrics_payload');
            $metrics = $metricsPayload === null
                ? null
                : $this->attemptMetrics->decode($this->payloads->reveal($metricsPayload));
            $diagnosticPayload = $data->nullableString('diagnostic_payload');
            $diagnostic = $diagnosticPayload === null
                ? null
                : $this->diagnostics->decode($this->payloads->reveal($diagnosticPayload));
            $provider = $metrics === null ? $data->nullableString('provider') : $metrics->provider;
            $model = $metrics === null ? $data->nullableString('model') : $metrics->model;
            $resolvedRoute = $metrics === null
                ? $data->nullableString('resolved_route')
                : $metrics->resolvedRoute;
            $modelTier = $metrics === null ? $data->nullableString('model_tier') : $metrics->modelTier;
            $raw[] = self::raw(
                hash('sha256', implode("\0", [
                    'invocation.attempt',
                    $invocationId->toString(),
                    (string) $number,
                    $status->value,
                ])),
                'invocation.attempt.'.$status->value,
                $finishedAt,
                $context['step_id'],
                invocationId: $invocationId,
                attempt: $number,
                attemptId: $attemptId,
                operationKey: $context['operation_key'],
                details: [
                    'step_execution_id' => $context['step_execution_id'],
                    'original_index' => $context['index'],
                    'candidate_number' => $context['index'] + 1,
                    'error_code' => $data->nullableString('error_code'),
                    'http_status_class' => $data->nullableString('http_status_class'),
                    'gateway_invocation_id' => $data->nullableString('gateway_invocation_id'),
                    'provider_request_id' => $data->nullableString('provider_request_id'),
                    'provider_generation_id' => $data->nullableString('provider_generation_id'),
                    'provider_id_source' => $data->nullableString('provider_id_source'),
                    'provider_request_outcome' => $data->nullableString('provider_request_outcome'),
                    'provider' => $provider,
                    'model' => $model,
                    'resolved_route' => $resolvedRoute,
                    'model_tier' => $modelTier,
                    'tokens' => $metrics?->tokens->toArray(),
                    'cost_usd' => $metrics?->cost?->toUsdDecimal(),
                    'latency_milliseconds' => $metrics?->latencyMilliseconds,
                    'provider_requests' => $metrics === null ? 0 : $metrics->providerRequests,
                    'usage_present' => $metrics !== null && $metrics->usagePresent,
                    'usage_complete' => $metrics !== null && $metrics->usageComplete,
                    'prompt_characters' => $metrics === null ? 0 : $metrics->promptCharacters,
                    'response_characters' => $metrics === null ? 0 : $metrics->responseCharacters,
                    'validation_stage' => $diagnostic?->stage->value,
                    'contract' => $diagnostic?->contract->value,
                    'schema_fingerprint' => $diagnostic?->schemaFingerprint,
                    'response_present' => $diagnostic?->responsePresent,
                    'response_bytes' => $diagnostic?->responseBytes,
                    'response_fingerprint' => $diagnostic?->responseFingerprint,
                    'decode_status' => $diagnostic?->decodeStatus->value,
                    'expected_root_type' => $diagnostic === null ? null : 'object',
                    'actual_root_type' => $diagnostic?->decodedRootType,
                    'validation_path' => $diagnostic?->validationPath,
                    'validation_keyword' => $diagnostic?->validationKeyword,
                    'finish_reason' => $diagnostic?->finishReason,
                    'retry_decision' => $diagnostic?->retryDecision,
                    'terminal_timestamp' => $finishedAt->format(DATE_ATOM),
                ],
            );
        }

        return $raw;
    }

    /**
     * @param  list<string>  $observationIds
     * @return array<string, int>
     */
    private function observationVersions(RunId $runId, array $observationIds): array
    {
        $tenantId = $this->tenant->id();
        foreach (array_chunk($observationIds, 500) as $chunk) {
            $this->database->table($this->observationsTable)->insertOrIgnore(array_map(
                static fn (string $observationId): array => [
                    'tenant_id' => $tenantId,
                    'run_id' => $runId->toString(),
                    'observation_id' => $observationId,
                ],
                $chunk,
            ));
        }

        $versions = [];
        $rows = $this->database->table($this->observationsTable)
            ->where('tenant_id', $tenantId)
            ->where('run_id', $runId->toString())
            ->whereIn('observation_id', $observationIds)
            ->get();
        foreach ($rows as $row) {
            $data = DatabaseRow::from($row);
            $versions[$data->string('observation_id')] = $data->integer('sequence');
        }
        if (count($versions) !== count($observationIds)) {
            throw new UnexpectedValueException('Timeline observation ledger is incomplete.');
        }

        return $versions;
    }

    private function runRow(RunId $runId): DatabaseRow
    {
        $row = $this->database->table($this->runsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $runId->toString())
            ->first();

        return $row === null
            ? throw ExecutionRecordNotFoundException::for($runId->toString())
            : DatabaseRow::from($row);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{id: string, type: string, occurred_at: DateTimeImmutable, step_id: ?StepId, invocation_id: ?InvocationId, attempt: ?int, attempt_id: ?InvocationAttemptId, operation_key: ?string, details: array<string, mixed>}
     */
    private static function raw(
        string $id,
        string $type,
        DateTimeImmutable $occurredAt,
        ?StepId $stepId = null,
        ?InvocationId $invocationId = null,
        ?int $attempt = null,
        ?InvocationAttemptId $attemptId = null,
        ?string $operationKey = null,
        array $details = [],
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'occurred_at' => $occurredAt,
            'step_id' => $stepId,
            'invocation_id' => $invocationId,
            'attempt' => $attempt,
            'attempt_id' => $attemptId,
            'operation_key' => $operationKey,
            'details' => $details,
        ];
    }

    private static function eventTime(EventBase $event): DateTimeImmutable
    {
        return match ($event::class) {
            WorkflowCreated::class,
            WorkflowRecoveryStarted::class,
            WorkflowCompleted::class,
            StepStarted::class,
            StepContinued::class,
            StepCompleted::class,
            StepDegraded::class,
            StepFailed::class,
            CandidateReviewRequested::class,
            ExternalInputRequested::class,
            LlmCallReserved::class,
            MemoryCommitted::class,
            UsageRecorded::class,
            InvocationRecoveryRequired::class => $event->occurredAt,
            default => throw new UnexpectedValueException('Domain event has no timeline timestamp.'),
        };
    }

    private static function eventStepId(EventBase $event): ?StepId
    {
        return match ($event::class) {
            StepStarted::class,
            StepContinued::class,
            StepCompleted::class,
            StepDegraded::class,
            StepFailed::class,
            CandidateReviewRequested::class,
            ExternalInputRequested::class,
            MemoryCommitted::class,
            UsageRecorded::class => $event->stepId,
            default => null,
        };
    }

    private static function eventInvocationId(EventBase $event): ?InvocationId
    {
        return match ($event::class) {
            UsageRecorded::class,
            InvocationRecoveryRequired::class => $event->invocationId,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function eventDetails(EventBase $event, string $logicalType): array
    {
        $details = ['event_type' => $logicalType];

        return match ($event::class) {
            WorkflowCreated::class => $details + [
                'workflow_name' => $event->workflowName,
                'workflow_version' => $event->workflowVersion,
            ],
            WorkflowRecoveryStarted::class => $details + [
                'parent_run_id' => $event->parentRunId->toString(),
                'action' => $event->action->value,
                'step_id' => $event->stepId->toString(),
            ],
            StepStarted::class => $details + ['step_type' => $event->stepType->toString()],
            StepFailed::class => $details + ['error_code' => $event->errorCode],
            StepDegraded::class => $details + [
                'expected' => $event->expected,
                'succeeded' => $event->succeeded,
                'failed' => $event->expected - $event->succeeded,
                'failure_codes' => $event->failureCodes,
            ],
            CandidateReviewRequested::class => $details + [
                'scope' => $event->scope,
                'candidate_count' => count($event->candidateIds),
            ],
            ExternalInputRequested::class => $details + ['input_key' => $event->key],
            LlmCallReserved::class => $details + [
                'call' => $event->call,
                'limit' => $event->limit,
            ],
            UsageRecorded::class => $details + [
                'provider' => $event->provider,
                'model' => $event->model,
                'provider_requests' => $event->providerRequests,
            ],
            InvocationRecoveryRequired::class => $details + ['reason' => $event->reason],
            default => $details,
        };
    }

    private static function invocationId(?string $value): ?InvocationId
    {
        return $value === null ? null : InvocationId::fromString($value);
    }

    /** @return 'pending'|'claimed'|'delivered'|'quarantined' */
    private static function deliveryStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'pending',
            'delivering' => 'claimed',
            'delivered' => 'delivered',
            'failed' => 'quarantined',
            default => throw new UnexpectedValueException("Unsupported outbox status [{$status}]."),
        };
    }

    private static function encodeCursor(
        DateTimeImmutable $updatedAt,
        RunId $runId,
        string $tenantId,
        ?RunStatus $status,
    ): string {
        $json = json_encode([
            'schema_version' => 1,
            'updated_at' => $updatedAt->format(DATE_ATOM),
            'id' => $runId->toString(),
            'tenant_hash' => hash('sha256', $tenantId),
            'status' => $status?->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{updated_at: DateTimeImmutable, id: string} */
    private static function decodeCursor(
        string $cursor,
        string $tenantId,
        ?RunStatus $status,
    ): array {
        $padding = strlen($cursor) % 4;
        if ($padding !== 0) {
            $cursor .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($json)) {
            throw new InvalidArgumentException('Run page cursor is invalid.');
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Run page cursor is invalid.', previous: $error);
        }
        if (
            ! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 1
            || ! is_string($decoded['updated_at'] ?? null)
            || ! is_string($decoded['id'] ?? null)
            || ! is_string($decoded['tenant_hash'] ?? null)
            || ! array_key_exists('status', $decoded)
            || ($decoded['status'] !== null && ! is_string($decoded['status']))
        ) {
            throw new InvalidArgumentException('Run page cursor is invalid.');
        }
        if (
            ! hash_equals(hash('sha256', $tenantId), $decoded['tenant_hash'])
            || $decoded['status'] !== $status?->value
        ) {
            throw new InvalidArgumentException('Run page cursor does not match the active query.');
        }
        try {
            $updatedAt = new DateTimeImmutable($decoded['updated_at']);
            $id = RunId::fromString($decoded['id'])->toString();
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Run page cursor is invalid.', previous: $error);
        }

        return ['updated_at' => $updatedAt, 'id' => $id];
    }
}
