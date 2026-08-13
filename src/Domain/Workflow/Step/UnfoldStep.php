<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

/**
 * Expand one accepted source artifact into ordered child artifacts.
 *
 * Units are executed sequentially. Candidate generation within a unit may fan out.
 */
final readonly class UnfoldStep implements ArtifactStepBase, StepBase
{
    public function __construct(
        private StepId $id,
        public ArtifactType $sourceArtifact,
        public ArtifactType $childArtifact,
        public int $candidateCount,
        public bool $judge,
        public int $maxUnits = 20,
        public string $modelPolicyId = 'default',
    ) {
        if ($candidateCount < 1) {
            throw new InvalidArgumentException('UNFOLD candidate count must be at least 1.');
        }

        if ($maxUnits < 1) {
            throw new InvalidArgumentException('UNFOLD max units must be at least 1.');
        }
        if (trim($modelPolicyId) === '') {
            throw new InvalidArgumentException('UNFOLD model policy id must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::unfold();
    }

    public function artifactReads(): array
    {
        return [$this->sourceArtifact->toString()];
    }

    public function artifactWrites(): array
    {
        return [$this->childArtifact->toString()];
    }
}
