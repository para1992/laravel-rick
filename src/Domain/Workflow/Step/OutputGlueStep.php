<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Interface\TerminalStepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class OutputGlueStep implements ArtifactStepBase, StepBase, TerminalStepBase
{
    public function __construct(
        private StepId $id,
        public ?string $artifactKey = null,
    ) {}

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::outputGlue();
    }

    public function artifactReads(): array
    {
        return $this->artifactKey === null ? [] : [$this->artifactKey];
    }

    public function artifactWrites(): array
    {
        return [];
    }
}
