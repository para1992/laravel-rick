<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\LabeledStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class ApplicationStep implements ArtifactStepBase, StepBase, LabeledStepBase
{
    /**
     * @param  list<string>  $reads
     */
    public function __construct(
        private StepId $id,
        public string $handlerClass,
        public int $handlerVersion = 1,
        public ?string $label = null,
        public array $reads = [],
    ) {
        if (trim($handlerClass) === '') {
            throw new InvalidArgumentException('Application step handler class must not be empty.');
        }
        if ($handlerVersion < 1) {
            throw new InvalidArgumentException('Application step handler version must be at least 1.');
        }
        if ($label !== null && trim($label) === '') {
            throw new InvalidArgumentException('Application step label must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::application();
    }

    public function artifactReads(): array
    {
        return $this->reads;
    }

    public function artifactWrites(): array
    {
        return [];
    }

    public function label(): ?string
    {
        return $this->label;
    }
}
