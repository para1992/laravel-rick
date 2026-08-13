<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class InvocationRecoveryRequired implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public InvocationId $invocationId,
        public string $reason,
        public DateTimeImmutable $occurredAt,
    ) {}
}
