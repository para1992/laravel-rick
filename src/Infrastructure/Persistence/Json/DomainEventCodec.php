<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use DateTimeImmutable;
use JsonException;
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
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use UnexpectedValueException;

final readonly class DomainEventCodec
{
    private const int VERSION = 1;

    public function type(EventBase $event): string
    {
        return match ($event::class) {
            WorkflowCreated::class => 'workflow.created',
            WorkflowRecoveryStarted::class => 'workflow.recovery_started',
            StepStarted::class => 'step.started',
            LlmCallReserved::class => 'llm.call_reserved',
            StepCompleted::class => 'step.completed',
            StepContinued::class => 'step.continued',
            StepDegraded::class => 'step.degraded',
            StepFailed::class => 'step.failed',
            WorkflowCompleted::class => 'workflow.completed',
            CandidateReviewRequested::class => 'candidate.review_requested',
            ExternalInputRequested::class => 'external_input.requested',
            MemoryCommitted::class => 'memory.committed',
            UsageRecorded::class => 'usage.recorded',
            InvocationRecoveryRequired::class => 'invocation.recovery_required',
            default => throw new UnexpectedValueException(sprintf(
                'Domain event [%s] has no registered logical type.',
                $event::class,
            )),
        };
    }

    public function runId(EventBase $event): RunId
    {
        return match ($event::class) {
            WorkflowCreated::class,
            WorkflowRecoveryStarted::class,
            StepStarted::class,
            LlmCallReserved::class,
            StepCompleted::class,
            StepContinued::class,
            StepDegraded::class,
            StepFailed::class,
            WorkflowCompleted::class,
            CandidateReviewRequested::class,
            ExternalInputRequested::class,
            MemoryCommitted::class,
            UsageRecorded::class,
            InvocationRecoveryRequired::class => $event->runId,
            default => throw new UnexpectedValueException(sprintf(
                'Domain event [%s] has no registered run identifier.',
                $event::class,
            )),
        };
    }

    public function encode(EventBase $event): string
    {
        return json_encode([
            'schema_version' => self::VERSION,
            'event' => [
                'id' => $event->eventId(),
                'type' => $this->type($event),
                'data' => $this->data($event),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $logicalType, string $payload): EventBase
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted domain event is not valid JSON.', previous: $error);
        }

        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported domain-event schema version.');
        }

        $envelope = self::array($decoded['event'] ?? null, 'event envelope');
        $type = self::string($envelope['type'] ?? null, 'event type');
        $eventId = self::string($envelope['id'] ?? null, 'event ID');
        $data = self::array($envelope['data'] ?? null, 'event data');

        if ($type !== $logicalType) {
            throw new UnexpectedValueException('Domain-event logical type does not match its envelope.');
        }

        $event = $this->event($type, $data);
        if (! hash_equals($event->eventId(), $eventId)) {
            throw new UnexpectedValueException('Domain-event deterministic ID does not match its payload.');
        }

        return $event;
    }

    /** @return array<string, mixed> */
    private function data(EventBase $event): array
    {
        return match ($event::class) {
            WorkflowCreated::class => [
                'run_id' => $event->runId->toString(),
                'workflow_name' => $event->workflowName,
                'workflow_version' => $event->workflowVersion,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            WorkflowRecoveryStarted::class => [
                'run_id' => $event->runId->toString(),
                'parent_run_id' => $event->parentRunId->toString(),
                'action' => $event->action->value,
                'step_id' => $event->stepId->toString(),
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            StepStarted::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'step_type' => $event->stepType->toString(),
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            LlmCallReserved::class => [
                'run_id' => $event->runId->toString(),
                'call' => $event->call,
                'limit' => $event->limit,
                'purpose' => $event->purpose,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            StepCompleted::class,
            StepContinued::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'metadata' => $event->metadata,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            StepDegraded::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'expected' => $event->expected,
                'succeeded' => $event->succeeded,
                'failure_codes' => $event->failureCodes,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            StepFailed::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'error_code' => $event->errorCode,
                'message' => $event->message,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            WorkflowCompleted::class => [
                'run_id' => $event->runId->toString(),
                'output' => $event->output,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            CandidateReviewRequested::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'scope' => $event->scope,
                'candidate_ids' => array_map(
                    static fn (CandidateId $id): string => $id->toString(),
                    $event->candidateIds,
                ),
                'context' => $event->context,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            ExternalInputRequested::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'key' => $event->key,
                'prompt' => $event->prompt,
                'schema' => $event->schema,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            MemoryCommitted::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'candidate_id' => $event->candidateId->toString(),
                'unit_id' => $event->unitId,
                'memory_version' => $event->memoryVersion,
                'memory_hash' => $event->memoryHash,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            UsageRecorded::class => [
                'run_id' => $event->runId->toString(),
                'step_id' => $event->stepId->toString(),
                'invocation_id' => $event->invocationId->toString(),
                'purpose' => $event->purpose,
                'model_tier' => $event->modelTier,
                'provider' => $event->provider,
                'model' => $event->model,
                'tokens' => $event->tokens->toArray(),
                'cost_usd' => $event->cost?->toUsdDecimal(),
                'latency_milliseconds' => $event->latencyMilliseconds,
                'provider_requests' => $event->providerRequests,
                'usage_complete' => $event->usageComplete,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            InvocationRecoveryRequired::class => [
                'run_id' => $event->runId->toString(),
                'invocation_id' => $event->invocationId->toString(),
                'reason' => $event->reason,
                'occurred_at' => self::timestamp($event->occurredAt),
            ],
            default => throw new UnexpectedValueException(sprintf(
                'Domain event [%s] cannot be encoded.',
                $event::class,
            )),
        };
    }

    /** @param array<string, mixed> $data */
    private function event(string $type, array $data): EventBase
    {
        $runId = RunId::fromString(self::string($data['run_id'] ?? null, 'run ID'));
        $occurredAt = self::date($data['occurred_at'] ?? null);

        return match ($type) {
            'workflow.created' => new WorkflowCreated(
                $runId,
                self::string($data['workflow_name'] ?? null, 'workflow name'),
                self::string($data['workflow_version'] ?? null, 'workflow version'),
                $occurredAt,
            ),
            'workflow.recovery_started' => new WorkflowRecoveryStarted(
                $runId,
                RunId::fromString(self::string($data['parent_run_id'] ?? null, 'parent run ID')),
                RunRecoveryAction::from(self::string($data['action'] ?? null, 'recovery action')),
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                $occurredAt,
            ),
            'step.started' => new StepStarted(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                StepType::fromString(self::string($data['step_type'] ?? null, 'step type')),
                $occurredAt,
            ),
            'llm.call_reserved' => new LlmCallReserved(
                $runId,
                self::integer($data['call'] ?? null, 'reserved call'),
                self::integer($data['limit'] ?? null, 'call limit'),
                self::string($data['purpose'] ?? null, 'call purpose'),
                $occurredAt,
            ),
            'step.completed' => new StepCompleted(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                self::array($data['metadata'] ?? null, 'step metadata'),
                $occurredAt,
            ),
            'step.continued' => new StepContinued(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                self::array($data['metadata'] ?? null, 'step metadata'),
                $occurredAt,
            ),
            'step.degraded' => new StepDegraded(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                self::integer($data['expected'] ?? null, 'expected invocation count'),
                self::integer($data['succeeded'] ?? null, 'successful invocation count'),
                array_map(
                    static fn (mixed $code): string => self::string($code, 'failure code'),
                    self::list($data['failure_codes'] ?? null, 'failure codes'),
                ),
                $occurredAt,
            ),
            'step.failed' => new StepFailed(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                self::string($data['error_code'] ?? null, 'error code'),
                self::string($data['message'] ?? null, 'safe error message'),
                $occurredAt,
            ),
            'workflow.completed' => new WorkflowCompleted(
                $runId,
                self::string($data['output'] ?? null, 'workflow output'),
                $occurredAt,
            ),
            'candidate.review_requested' => new CandidateReviewRequested(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                self::string($data['scope'] ?? null, 'review scope'),
                array_map(
                    static fn (mixed $id): CandidateId => CandidateId::fromString(
                        self::string($id, 'candidate ID'),
                    ),
                    self::list($data['candidate_ids'] ?? null, 'candidate IDs'),
                ),
                self::array($data['context'] ?? null, 'review context'),
                $occurredAt,
            ),
            'external_input.requested' => new ExternalInputRequested(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                self::string($data['key'] ?? null, 'input key'),
                self::string($data['prompt'] ?? null, 'input prompt'),
                $data['schema'] === null
                    ? null
                    : self::array($data['schema'], 'input schema'),
                $occurredAt,
            ),
            'memory.committed' => new MemoryCommitted(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                CandidateId::fromString(self::string($data['candidate_id'] ?? null, 'candidate ID')),
                self::string($data['unit_id'] ?? null, 'unit ID'),
                self::integer($data['memory_version'] ?? null, 'memory version'),
                self::string($data['memory_hash'] ?? null, 'memory hash'),
                $occurredAt,
            ),
            'usage.recorded' => new UsageRecorded(
                $runId,
                StepId::fromString(self::string($data['step_id'] ?? null, 'step ID')),
                InvocationId::fromString(self::string($data['invocation_id'] ?? null, 'invocation ID')),
                self::string($data['purpose'] ?? null, 'purpose'),
                self::string($data['model_tier'] ?? null, 'model tier'),
                self::string($data['provider'] ?? null, 'provider'),
                self::string($data['model'] ?? null, 'model'),
                self::tokens(self::array($data['tokens'] ?? null, 'token usage')),
                $data['cost_usd'] === null
                    ? null
                    : InvocationCost::fromUsd(self::string($data['cost_usd'], 'cost')),
                self::nullableInteger($data['latency_milliseconds'] ?? null, 'latency'),
                self::integer($data['provider_requests'] ?? null, 'provider requests'),
                self::boolean($data['usage_complete'] ?? null, 'usage completeness'),
                $occurredAt,
            ),
            'invocation.recovery_required' => new InvocationRecoveryRequired(
                $runId,
                InvocationId::fromString(self::string($data['invocation_id'] ?? null, 'invocation ID')),
                self::string($data['reason'] ?? null, 'recovery reason'),
                $occurredAt,
            ),
            default => throw new UnexpectedValueException("Unsupported domain-event type [{$type}]."),
        };
    }

    /** @param array<string, mixed> $data */
    private static function tokens(array $data): TokenUsage
    {
        return new TokenUsage(
            self::integer($data['input_tokens'] ?? null, 'input tokens'),
            self::integer($data['output_tokens'] ?? null, 'output tokens'),
            self::integer($data['total_tokens'] ?? null, 'total tokens'),
            self::integer($data['cached_input_tokens'] ?? null, 'cached input tokens'),
            self::integer($data['cache_write_input_tokens'] ?? null, 'cache-write input tokens'),
            self::integer($data['reasoning_tokens'] ?? null, 'reasoning tokens'),
        );
    }

    /** @return array<string, mixed> */
    private static function array(mixed $value, string $name): array
    {
        return JsonInput::map($value, $name);
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $name): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("Persisted {$name} must be a list.");
        }

        return $value;
    }

    private static function string(mixed $value, string $name): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException("Persisted {$name} must be a string.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $name): int
    {
        if (! is_int($value)) {
            throw new UnexpectedValueException("Persisted {$name} must be an integer.");
        }

        return $value;
    }

    private static function nullableInteger(mixed $value, string $name): ?int
    {
        return $value === null ? null : self::integer($value, $name);
    }

    private static function boolean(mixed $value, string $name): bool
    {
        if (! is_bool($value)) {
            throw new UnexpectedValueException("Persisted {$name} must be a boolean.");
        }

        return $value;
    }

    private static function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.uP');
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $value = self::string($value, 'event timestamp');

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $error) {
            throw new UnexpectedValueException('Persisted event timestamp is invalid.', previous: $error);
        }
    }
}
