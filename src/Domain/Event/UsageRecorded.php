<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class UsageRecorded implements EventBase
{
    use HasDeterministicEventId;

    public function __construct(
        public RunId $runId,
        public StepId $stepId,
        public InvocationId $invocationId,
        public string $purpose,
        public string $modelTier,
        public string $provider,
        public string $model,
        public TokenUsage $tokens,
        public ?InvocationCost $cost,
        public ?int $latencyMilliseconds,
        public int $providerRequests,
        public bool $usageComplete,
        public DateTimeImmutable $occurredAt,
    ) {}
}
