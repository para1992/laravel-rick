<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class WorkflowRecoveryStarted implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public RunId $parentRunId,
        public RunRecoveryAction $action,
        public StepId $stepId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
