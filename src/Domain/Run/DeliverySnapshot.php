<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class DeliverySnapshot implements JsonSerializable
{
    /**
     * @param  array{pending: int, claimed: int, delivered: int, quarantined: int}  $counts
     * @param  list<DeliveryRecord>  $records
     */
    public function __construct(
        public RunId $runId,
        public array $counts,
        public array $records,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $this->runId->toString(),
            'counts' => $this->counts,
            'records' => array_map(
                static fn (DeliveryRecord $record): array => $record->toArray(),
                $this->records,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
