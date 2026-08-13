<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class WorkflowCreated implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public string $workflowName,
        public string $workflowVersion,
        public DateTimeImmutable $occurredAt,
    ) {}
}
