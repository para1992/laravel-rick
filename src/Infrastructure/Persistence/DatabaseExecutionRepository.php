<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Rick\Laravel\Application\Execution\Exception\ConcurrentExecutionModificationException;
use Rick\Laravel\Application\Execution\Exception\ExecutionRecordNotFoundException;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\StepExecutionStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionMode;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Persistence\Json\AttemptMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionRequestCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionResponseCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\StructuredResponseDiagnosticCodec;

final readonly class DatabaseExecutionRepository implements ExecutionRepositoryBase
{
    public function __construct(
        private ConnectionInterface $database,
        private CompletionRequestCodec $requests,
        private CompletionResponseCodec $responses,
        private CompletionMetricsCodec $metrics,
        private AttemptMetricsCodec $attemptMetrics,
        private StructuredResponseDiagnosticCodec $diagnostics,
        private PayloadProtectorBase $payloads,
        private TenantContextBase $tenant,
        private string $executionsTable = 'rick_step_executions',
        private string $invocationsTable = 'rick_llm_invocations',
        private string $attemptsTable = 'rick_invocation_attempts',
    ) {}

    public function add(StepExecution $execution, array $invocations): void
    {
        $now = new DateTimeImmutable;
        $latestSequence = $this->database->table($this->executionsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $execution->runId()->toString())
            ->where('step_id', $execution->stepId()->toString())
            ->max('sequence');
        $sequence = DatabaseRow::integerValue($latestSequence, 'sequence', 0) + 1;
        $this->database->table($this->executionsTable)->insert([
            'id' => $execution->id()->toString(),
            'tenant_id' => $this->tenant->id(),
            'run_id' => $execution->runId()->toString(),
            'step_id' => $execution->stepId()->toString(),
            'sequence' => $sequence,
            'status' => $execution->status()->value,
            'expected_invocations' => $execution->expectedInvocations(),
            'dispatched_invocations' => $execution->dispatchedInvocations(),
            'completion_policy' => $execution->completionPolicy()->mode->value,
            'minimum_successful_invocations' => $execution->completionPolicy()->minimumSuccessful,
            'version' => $execution->version(),
            'error_code' => null,
            'error_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($invocations as $invocation) {
            $this->database->table($this->invocationsTable)->insert($this->invocationRow($invocation, true));
        }
    }

    public function findForStep(RunId $runId, StepId $stepId): ?StepExecution
    {
        $row = $this->database->table($this->executionsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->where('step_id', $stepId->toString())
            ->orderByDesc('sequence')
            ->first();

        return $row === null ? null : $this->executionFromRow($row);
    }

    public function getInvocation(InvocationId $id): LlmInvocation
    {
        $row = $this->database->table($this->invocationsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $id->toString())
            ->first();

        return $row === null
            ? throw ExecutionRecordNotFoundException::for($id->toString())
            : $this->invocationFromRow($row);
    }

    public function addAttempt(InvocationAttempt $attempt): void
    {
        $now = new DateTimeImmutable;
        $this->database->table($this->attemptsTable)->insert([
            'tenant_id' => $this->tenant->id(),
            'id' => $attempt->id()->toString(),
            'invocation_id' => $attempt->invocationId()->toString(),
            'run_id' => $attempt->runId()->toString(),
            'attempt_number' => $attempt->number(),
            'status' => $attempt->status()->value,
            'request_fingerprint' => $attempt->requestFingerprint(),
            'gateway_invocation_id' => $attempt->providerIdentifiers()?->gatewayInvocationId,
            'provider_request_id' => $attempt->providerRequestId(),
            'provider_generation_id' => $attempt->providerIdentifiers()?->providerGenerationId,
            'provider_id_source' => $attempt->providerIdentifiers()?->source->value,
            'provider' => $attempt->metrics()?->provider,
            'model' => $attempt->metrics()?->model,
            'resolved_route' => $attempt->metrics()?->resolvedRoute,
            'model_tier' => $attempt->metrics()?->modelTier,
            'metrics_payload' => $attempt->metrics() === null
                ? null
                : $this->payloads->protect($this->attemptMetrics->encode($attempt->metrics())),
            'diagnostic_payload' => $attempt->diagnostic() === null
                ? null
                : $this->payloads->protect($this->diagnostics->encode($attempt->diagnostic())),
            'provider_request_outcome' => $attempt->outcome()?->value,
            'error_code' => $attempt->errorCode(),
            'error_message' => $attempt->errorMessage(),
            'http_status_class' => $attempt->httpStatusClass(),
            'started_at' => $attempt->startedAt(),
            'finished_at' => $attempt->finishedAt(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function saveAttempt(InvocationAttempt $attempt): void
    {
        $updated = $this->database->table($this->attemptsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $attempt->id()->toString())
            ->update([
                'status' => $attempt->status()->value,
                'gateway_invocation_id' => $attempt->providerIdentifiers()?->gatewayInvocationId,
                'provider_request_id' => $attempt->providerRequestId(),
                'provider_generation_id' => $attempt->providerIdentifiers()?->providerGenerationId,
                'provider_id_source' => $attempt->providerIdentifiers()?->source->value,
                'provider' => $attempt->metrics()?->provider,
                'model' => $attempt->metrics()?->model,
                'resolved_route' => $attempt->metrics()?->resolvedRoute,
                'model_tier' => $attempt->metrics()?->modelTier,
                'metrics_payload' => $attempt->metrics() === null
                    ? null
                    : $this->payloads->protect($this->attemptMetrics->encode($attempt->metrics())),
                'diagnostic_payload' => $attempt->diagnostic() === null
                    ? null
                    : $this->payloads->protect($this->diagnostics->encode($attempt->diagnostic())),
                'provider_request_outcome' => $attempt->outcome()?->value,
                'error_code' => $attempt->errorCode(),
                'error_message' => $attempt->errorMessage(),
                'http_status_class' => $attempt->httpStatusClass(),
                'finished_at' => $attempt->finishedAt(),
                'updated_at' => new DateTimeImmutable,
            ]);

        if ($updated !== 1) {
            throw ConcurrentExecutionModificationException::for($attempt->id()->toString());
        }
    }

    public function latestAttemptFor(InvocationId $id): ?InvocationAttempt
    {
        $row = $this->database->table($this->attemptsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('invocation_id', $id->toString())
            ->orderByDesc('attempt_number')
            ->first();

        return $row === null ? null : $this->attemptFromRow($row);
    }

    public function attemptsForRun(RunId $runId): array
    {
        return array_values($this->database->table($this->attemptsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->orderBy('started_at')
            ->orderBy('attempt_number')
            ->get()
            ->map(fn (object $row): InvocationAttempt => $this->attemptFromRow($row))
            ->all());
    }

    public function staleRunning(DateTimeImmutable $expiredBefore, int $limit): array
    {
        return array_values($this->database->table($this->invocationsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('status', InvocationStatus::Running->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $expiredBefore)
            ->orderBy('lease_expires_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): LlmInvocation => $this->invocationFromRow($row))
            ->all());
    }

    public function saveExecution(StepExecution $execution, int $expectedVersion): void
    {
        $updated = $this->database->table($this->executionsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $execution->id()->toString())
            ->where('version', $expectedVersion)
            ->update([
                'status' => $execution->status()->value,
                'expected_invocations' => $execution->expectedInvocations(),
                'dispatched_invocations' => $execution->dispatchedInvocations(),
                'completion_policy' => $execution->completionPolicy()->mode->value,
                'minimum_successful_invocations' => $execution->completionPolicy()->minimumSuccessful,
                'version' => $execution->version(),
                'error_code' => $execution->errorCode(),
                'error_message' => $execution->errorMessage(),
                'updated_at' => new DateTimeImmutable,
            ]);

        if ($updated !== 1) {
            throw ConcurrentExecutionModificationException::for($execution->id()->toString());
        }
    }

    public function saveInvocation(LlmInvocation $invocation, int $expectedVersion): void
    {
        $updated = $this->database->table($this->invocationsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('id', $invocation->id()->toString())
            ->where('version', $expectedVersion)
            ->update($this->invocationRow($invocation, false));

        if ($updated !== 1) {
            throw ConcurrentExecutionModificationException::for($invocation->id()->toString());
        }
    }

    public function invocationsFor(StepExecutionId $executionId): array
    {
        return array_values($this->database->table($this->invocationsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('step_execution_id', $executionId->toString())
            ->orderBy('invocation_index')
            ->get()
            ->map(fn (object $row): LlmInvocation => $this->invocationFromRow($row))
            ->all());
    }

    public function invocationsForRun(RunId $runId): array
    {
        return array_values($this->database->table($this->invocationsTable)
            ->where('tenant_id', $this->tenant->id())
            ->where('run_id', $runId->toString())
            ->orderBy('created_at')
            ->orderBy('invocation_index')
            ->get()
            ->map(fn (object $row): LlmInvocation => $this->invocationFromRow($row))
            ->all());
    }

    private function executionFromRow(object $row): StepExecution
    {
        $data = DatabaseRow::from($row);

        return StepExecution::restore(
            StepExecutionId::fromString($data->string('id')),
            RunId::fromString($data->string('run_id')),
            StepId::fromString($data->string('step_id')),
            $data->integer('expected_invocations'),
            StepExecutionStatus::from($data->string('status')),
            $data->integer('version'),
            $data->nullableString('error_code'),
            $data->nullableString('error_message'),
            $data->integer('dispatched_invocations'),
            InvocationCompletionPolicy::restore(
                InvocationCompletionMode::from(
                    $data->nullableStringOr('completion_policy')
                        ?? InvocationCompletionMode::AllRequired->value,
                ),
                $data->has('minimum_successful_invocations')
                    ? ($data->value('minimum_successful_invocations') === null
                        ? null
                        : $data->integer('minimum_successful_invocations'))
                    : null,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function invocationRow(LlmInvocation $invocation, bool $creating): array
    {
        $row = [
            'tenant_id' => $this->tenant->id(),
            'run_id' => $invocation->runId()->toString(),
            'step_execution_id' => $invocation->executionId()->toString(),
            'step_id' => $invocation->stepId()->toString(),
            'invocation_index' => $invocation->index(),
            'status' => $invocation->status()->value,
            'attempts' => $invocation->attempts(),
            'version' => $invocation->version(),
            'request_payload' => $this->payloads->protect($this->requests->encode($invocation->request())),
            'response_payload' => $invocation->response() === null
                ? null
                : $this->payloads->protect($this->responses->encode($invocation->response())),
            'metrics_payload' => $invocation->metrics() === null
                ? null
                : $this->payloads->protect($this->metrics->encode($invocation->metrics())),
            'error_code' => $invocation->errorCode(),
            'error_message' => $invocation->errorMessage(),
            'lease_expires_at' => $invocation->leaseExpiresAt(),
            'source_run_id' => $invocation->sourceRunId()?->toString(),
            'source_invocation_id' => $invocation->sourceInvocationId()?->toString(),
            'updated_at' => new DateTimeImmutable,
        ];

        if ($creating) {
            $row['id'] = $invocation->id()->toString();
            $row['created_at'] = new DateTimeImmutable;
        }

        return $row;
    }

    private function invocationFromRow(object $row): LlmInvocation
    {
        $data = DatabaseRow::from($row);
        $request = $this->requests->decode($this->payloads->reveal($data->string('request_payload')));
        $responsePayload = $data->nullableString('response_payload');
        $metricsPayload = $data->nullableString('metrics_payload');
        $response = $responsePayload === null
            ? null
            : $this->responses->decode($this->payloads->reveal($responsePayload));
        $metrics = $metricsPayload === null
            ? null
            : $this->metrics->decode($this->payloads->reveal($metricsPayload));

        return LlmInvocation::restore(
            InvocationId::fromString($data->string('id')),
            StepExecutionId::fromString($data->string('step_execution_id')),
            RunId::fromString($data->string('run_id')),
            StepId::fromString($data->string('step_id')),
            $data->integer('invocation_index'),
            $request,
            InvocationStatus::from($data->string('status')),
            $data->integer('attempts'),
            $data->integer('version'),
            $response,
            $data->nullableString('error_code'),
            $data->nullableString('error_message'),
            $data->nullableTimestamp('lease_expires_at'),
            $metrics,
            ($sourceRunId = $data->nullableStringOr('source_run_id')) === null
                ? null
                : RunId::fromString($sourceRunId),
            ($sourceInvocationId = $data->nullableStringOr('source_invocation_id')) === null
                ? null
                : InvocationId::fromString($sourceInvocationId),
        );
    }

    private function attemptFromRow(object $row): InvocationAttempt
    {
        $data = DatabaseRow::from($row);
        $metricsPayload = $data->nullableStringOr('metrics_payload');
        $diagnosticPayload = $data->nullableStringOr('diagnostic_payload');
        $source = ProviderIdSource::tryFrom(
            $data->nullableStringOr('provider_id_source') ?? ProviderIdSource::Unavailable->value,
        ) ?? ProviderIdSource::Unavailable;
        $outcome = $data->nullableStringOr('provider_request_outcome');

        return InvocationAttempt::restore(
            InvocationAttemptId::fromString($data->string('id')),
            InvocationId::fromString($data->string('invocation_id')),
            RunId::fromString($data->string('run_id')),
            $data->integer('attempt_number'),
            $data->string('request_fingerprint'),
            InvocationAttemptStatus::from($data->string('status')),
            $data->timestamp('started_at'),
            $data->nullableTimestamp('finished_at'),
            $data->nullableString('provider_request_id'),
            $data->nullableString('error_code'),
            $data->nullableString('error_message'),
            $data->nullableStringOr('http_status_class'),
            $data->nullableStringOr('gateway_invocation_id'),
            $data->nullableStringOr('provider_generation_id'),
            $source,
            $metricsPayload === null
                ? null
                : $this->attemptMetrics->decode($this->payloads->reveal($metricsPayload)),
            $diagnosticPayload === null
                ? null
                : $this->diagnostics->decode($this->payloads->reveal($diagnosticPayload)),
            $outcome === null ? null : ProviderRequestOutcome::from($outcome),
        );
    }
}
