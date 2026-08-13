<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use InvalidArgumentException;
use JsonSerializable;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class PendingInteraction implements JsonSerializable
{
    private function __construct(
        public PendingInteractionType $type,
        public ?PendingReview $review,
        public ?PendingInput $input,
    ) {}

    public static function resolve(PendingReview $review, PendingInput $input): self
    {
        if ($review->exists() && $input->exists()) {
            throw new InvalidArgumentException('A run cannot await candidate review and external input simultaneously.');
        }
        if ($review->exists()) {
            return new self(PendingInteractionType::CandidateReview, $review, null);
        }
        if ($input->exists()) {
            return new self(PendingInteractionType::ExternalInput, null, $input);
        }

        return new self(PendingInteractionType::None, null, null);
    }

    public function exists(): bool
    {
        return $this->type !== PendingInteractionType::None;
    }

    public function stepId(): ?StepId
    {
        return $this->review->stepId ?? $this->input?->stepId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'exists' => $this->exists(),
            'type' => $this->type->value,
            'step_id' => $this->stepId()?->toString(),
            'review' => $this->review?->toArray(),
            'input' => $this->input?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
