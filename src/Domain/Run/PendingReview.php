<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class PendingReview implements JsonSerializable
{
    /** @param list<Candidate> $candidates */
    public function __construct(
        public ?StepId $stepId,
        public array $candidates,
    ) {}

    public function exists(): bool
    {
        return $this->stepId !== null && $this->candidates !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'exists' => $this->exists(),
            'step_id' => $this->stepId?->toString(),
            'candidates' => array_map(
                static fn (Candidate $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
