<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class StepStarted implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public StepId $stepId,
        public StepType $stepType,
        public DateTimeImmutable $occurredAt,
    ) {}
}
