<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class MapStep implements ArtifactStepBase, StepBase
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private StepId $id,
        public string $sourceArtifact,
        public string $sourcePath,
        public string $operationId,
        public ?string $operationVersion,
        public string $outputKey,
        public array $parameters = [],
        public int $maxItems = 50,
        public bool $includeSourceArtifact = false,
    ) {
        if ($maxItems < 1) {
            throw new InvalidArgumentException('Map max items must be positive.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::map();
    }

    public function artifactReads(): array
    {
        return [$this->sourceArtifact];
    }

    public function artifactWrites(): array
    {
        return [$this->outputKey];
    }
}
