<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class GenerateStep implements ArtifactStepBase, StepBase
{
    /** @param list<string> $readArtifacts */
    public function __construct(
        private StepId $id,
        public ArtifactType $artifact,
        public int $candidateCount,
        public ?string $outputKey = null,
        public array $readArtifacts = [],
        public string $modelPolicyId = 'default',
        public ?int $minimumSuccessful = null,
    ) {
        if ($candidateCount < 1) {
            throw new InvalidArgumentException('Candidate count must be at least 1.');
        }
        if (
            $minimumSuccessful !== null
            && ($minimumSuccessful < 1 || $minimumSuccessful > $candidateCount)
        ) {
            throw new InvalidArgumentException(
                'Minimum successful candidates must be between 1 and the candidate count.',
            );
        }

        $resolvedKey = $this->outputKey();

        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $resolvedKey) !== 1) {
            throw new InvalidArgumentException("Invalid generated artifact key [{$resolvedKey}].");
        }

        foreach ($readArtifacts as $key) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1) {
                throw new InvalidArgumentException('Generate read artifacts must contain valid artifact keys.');
            }
        }

        if (trim($modelPolicyId) === '') {
            throw new InvalidArgumentException('Generate model policy id must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::generate();
    }

    public function artifactReads(): array
    {
        return $this->readArtifacts;
    }

    public function artifactWrites(): array
    {
        return [];
    }

    public function outputKey(): string
    {
        return $this->outputKey ?? $this->artifact->toString();
    }
}
