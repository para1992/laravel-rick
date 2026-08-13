<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

use DateTimeImmutable;
use Rick\Laravel\Domain\Event\Interface\EventBase;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class CandidateReviewRequested implements EventBase
{
    use HasDeterministicEventId;

    /**
     * @param  list<CandidateId>  $candidateIds
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public RunId $runId,
        public StepId $stepId,
        public string $scope,
        public array $candidateIds,
        public array $context,
        public DateTimeImmutable $occurredAt,
    ) {}
}
