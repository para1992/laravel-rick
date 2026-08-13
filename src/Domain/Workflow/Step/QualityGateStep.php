<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class QualityGateStep implements ArtifactStepBase, StepBase
{
    public function __construct(
        private StepId $id,
        public string $artifactKey,
        public string $ruleSetId,
        public string $repairPolicyId = 'fail',
        public ?string $repairOperationId = null,
        public ?string $repairOperationVersion = null,
        public int $maxRepairs = 0,
        public ?string $outputKey = null,
    ) {
        if ($maxRepairs < 0) {
            throw new InvalidArgumentException('Quality gate max repairs cannot be negative.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::qualityGate();
    }

    public function resolvedOutputKey(): string
    {
        return $this->outputKey ?? $this->artifactKey;
    }

    public function artifactReads(): array
    {
        return [$this->artifactKey];
    }

    public function artifactWrites(): array
    {
        return [$this->resolvedOutputKey(), $this->resolvedOutputKey().'.quality'];
    }
}
