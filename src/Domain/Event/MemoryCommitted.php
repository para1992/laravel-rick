<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class MemoryCommitted implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public StepId $stepId,
        public CandidateId $candidateId,
        public string $unitId,
        public int $memoryVersion,
        public string $memoryHash,
        public DateTimeImmutable $occurredAt,
    ) {}
}
