<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use InvalidArgumentException;
use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class CandidateDecision implements JsonSerializable
{
    public function __construct(
        public StepId $stepId,
        public CandidateId $selectedCandidateId,
        public ?float $score,
        public string $reason,
        public string $policy = 'llm_judge',
        public ?string $selectionSeed = null,
    ) {
        if ($score !== null && ($score < 0 || $score > 100)) {
            throw new InvalidArgumentException('Decision score must be between 0 and 100.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'step_id' => $this->stepId->toString(),
            'selected_candidate_id' => $this->selectedCandidateId->toString(),
            'score' => $this->score,
            'reason' => $this->reason,
            'policy' => $this->policy,
            'selection_seed' => $this->selectionSeed,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
