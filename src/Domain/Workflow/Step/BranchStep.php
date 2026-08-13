<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class BranchStep implements ArtifactStepBase, StepBase
{
    public function __construct(
        private StepId $id,
        public string $conditionArtifact,
        public string $path,
        public string $operator,
        public mixed $expected,
        public string $whenTrue,
        public string $whenFalse,
        public string $outputKey,
    ) {
        if (! in_array($operator, ['equals', 'not_equals', 'contains', 'truthy', 'exists'], true)) {
            throw new InvalidArgumentException("Unsupported branch operator [{$operator}].");
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::branch();
    }

    public function artifactReads(): array
    {
        return [$this->conditionArtifact, $this->whenTrue, $this->whenFalse];
    }

    public function artifactWrites(): array
    {
        return [$this->outputKey];
    }
}
