<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class LlmCallReserved implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public int $call,
        public int $limit,
        public string $purpose,
        public DateTimeImmutable $occurredAt,
    ) {}
}
