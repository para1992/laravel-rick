<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use DateTimeImmutable;
use JsonSerializable;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class RunObservation implements JsonSerializable
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $id,
        public string $type,
        public int $version,
        public DateTimeImmutable $occurredAt,
        public ?StepId $stepId = null,
        public ?InvocationId $invocationId = null,
        public ?int $attempt = null,
        public ?string $operationKey = null,
        public array $details = [],
        public ?InvocationAttemptId $attemptId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 2,
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'step_id' => $this->stepId?->toString(),
            'invocation_id' => $this->invocationId?->toString(),
            'attempt' => $this->attempt,
            'attempt_id' => $this->attemptId?->toString(),
            'operation_key' => $this->operationKey,
            'details' => $this->details,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
