<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class Candidate implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public CandidateId $id,
        public StepId $stepId,
        public ArtifactType $artifact,
        public string $title,
        public string $summary,
        public array $payload,
        public string $content,
        public string $seedRandomString,
        public string $seedInterpretation,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'id' => $this->id->toString(),
            'step_id' => $this->stepId->toString(),
            'artifact' => $this->artifact->toString(),
            'title' => $this->title,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'content' => $this->content,
            'seed' => [
                'random_string' => $this->seedRandomString,
                'interpretation' => $this->seedInterpretation,
            ],
            'metadata' => $this->metadata,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
