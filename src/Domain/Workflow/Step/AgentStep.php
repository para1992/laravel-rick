<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\LabeledStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class AgentStep implements ArtifactStepBase, LabeledStepBase, StepBase
{
    /**
     * @param  list<string>  $reads
     */
    public function __construct(
        private StepId $id,
        public string $agentClass,
        public int $agentVersion = 1,
        public ?string $label = null,
        public string $modelPolicy = 'medium',
        public ?string $prompt = null,
        public array $reads = [],
    ) {
        if (trim($agentClass) === '') {
            throw new InvalidArgumentException('Agent step agent class must not be empty.');
        }
        if ($agentVersion < 1) {
            throw new InvalidArgumentException('Agent step agent version must be at least 1.');
        }
        if ($label !== null && trim($label) === '') {
            throw new InvalidArgumentException('Agent step label must not be empty.');
        }
        if (trim($modelPolicy) === '') {
            throw new InvalidArgumentException('Agent step model policy must not be empty.');
        }
        if ($prompt !== null && trim($prompt) === '') {
            throw new InvalidArgumentException('Agent step prompt must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::agent();
    }

    public function artifactReads(): array
    {
        return $this->reads;
    }

    public function artifactWrites(): array
    {
        return [$this->id->toString()];
    }

    public function label(): ?string
    {
        return $this->label;
    }
}
