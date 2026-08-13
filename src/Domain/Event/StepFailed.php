<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class StepFailed implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public StepId $stepId,
        public string $errorCode,
        public string $message,
        public DateTimeImmutable $occurredAt,
    ) {}
}
