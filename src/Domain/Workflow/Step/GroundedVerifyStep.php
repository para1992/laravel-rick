<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class GroundedVerifyStep implements ArtifactStepBase, StepBase
{
    /** @param list<string> $evidenceKeys */
    public function __construct(
        private StepId $id,
        public string $artifactKey,
        public array $evidenceKeys,
        public string $verificationOperationId = 'rick.verify.grounded',
        public ?string $verificationOperationVersion = null,
        public ?string $repairOperationId = null,
        public ?string $repairOperationVersion = null,
        public int $maxRepairs = 0,
        public ?string $outputKey = null,
        public int $minimumQuoteCharacters = 12,
    ) {
        if ($evidenceKeys === []) {
            throw new InvalidArgumentException('Grounded verification requires at least one evidence artifact.');
        }
        if ($maxRepairs < 0) {
            throw new InvalidArgumentException('Grounded verification max repairs cannot be negative.');
        }
        if ($minimumQuoteCharacters < 1) {
            throw new InvalidArgumentException('Grounded verification quote length must be positive.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::groundedVerify();
    }

    public function resolvedOutputKey(): string
    {
        return $this->outputKey ?? $this->artifactKey;
    }

    public function reportKey(): string
    {
        return $this->resolvedOutputKey().'.verification';
    }

    public function artifactReads(): array
    {
        return array_values(array_unique([$this->artifactKey, ...$this->evidenceKeys]));
    }

    public function artifactWrites(): array
    {
        return [$this->resolvedOutputKey(), $this->reportKey()];
    }
}
