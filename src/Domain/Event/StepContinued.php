<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class StepContinued implements EventBase
{
    use HasDeterministicEventId;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public RunId $runId,
        public StepId $stepId,
        public array $metadata,
        public DateTimeImmutable $occurredAt,
    ) {}
}
