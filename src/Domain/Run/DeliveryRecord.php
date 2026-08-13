<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use DateTimeImmutable;
use JsonSerializable;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;

final readonly class DeliveryRecord implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $deduplicationKey,
        public string $kind,
        public string $status,
        public int $attempts,
        public DateTimeImmutable $availableAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?InvocationId $invocationId = null,
        public ?string $eventType = null,
        public ?DateTimeImmutable $leaseExpiresAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?string $lastErrorCode = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'id' => $this->id,
            'deduplication_key' => $this->deduplicationKey,
            'kind' => $this->kind,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'invocation_id' => $this->invocationId?->toString(),
            'event_type' => $this->eventType,
            'available_at' => $this->availableAt->format(DATE_ATOM),
            'lease_expires_at' => $this->leaseExpiresAt?->format(DATE_ATOM),
            'delivered_at' => $this->deliveredAt?->format(DATE_ATOM),
            'last_error_code' => $this->lastErrorCode,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
