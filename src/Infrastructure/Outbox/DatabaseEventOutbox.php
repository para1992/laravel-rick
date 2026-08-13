<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Outbox;

use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;

final readonly class DatabaseEventOutbox implements EventOutboxBase
{
    public function __construct(
        private OutboxWriter $outbox,
        private DomainEventCodec $events,
        private PayloadProtectorBase $payloads,
    ) {}

    public function record(EventBase $event): void
    {
        $this->outbox->record(
            'domain_event',
            $this->events->runId($event)->toString(),
            null,
            $this->events->type($event),
            $this->payloads->protect($this->events->encode($event)),
            $event->eventId(),
        );
    }
}
