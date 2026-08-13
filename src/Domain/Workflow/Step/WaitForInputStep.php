<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class WaitForInputStep implements ArtifactStepBase, StepBase
{
    /** @param array<string, mixed>|null $schema */
    public function __construct(
        private StepId $id,
        public string $inputKey,
        public string $prompt,
        public ArtifactType $artifactType,
        public ?array $schema = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $inputKey) !== 1) {
            throw new InvalidArgumentException("Invalid external input key [{$inputKey}].");
        }
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('External input prompt must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::waitForInput();
    }

    public function artifactReads(): array
    {
        return [];
    }

    public function artifactWrites(): array
    {
        return [$this->inputKey];
    }
}
