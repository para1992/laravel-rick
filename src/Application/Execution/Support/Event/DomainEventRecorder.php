<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Event;

use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Domain\Run\WorkflowRun;

final readonly class DomainEventRecorder
{
    public function __construct(private EventOutboxBase $events) {}

    public function record(WorkflowRun $run): void
    {
        foreach ($run->releaseEvents() as $event) {
            $this->events->record($event);
        }
    }
}
