<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use DateTimeImmutable;
use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class RunSummary implements JsonSerializable
{
    public function __construct(
        public RunId $id,
        public RunStatus $status,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?RunRecovery $recovery = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'schema_version' => 1,
            'id' => $this->id->toString(),
            'status' => $this->status->value,
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];

        if ($this->recovery !== null) {
            $data['recovery'] = $this->recovery->toArray();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
