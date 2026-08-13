<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class RunTimeline implements JsonSerializable
{
    /** @param list<RunObservation> $observations */
    public function __construct(
        public RunId $runId,
        public int $runVersion,
        public int $latestVersion,
        public array $observations,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $this->runId->toString(),
            'run_version' => $this->runVersion,
            'latest_version' => $this->latestVersion,
            'observations' => array_map(
                static fn (RunObservation $observation): array => $observation->toArray(),
                $this->observations,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
