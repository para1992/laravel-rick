<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class LlmOperationStep implements ArtifactStepBase, StepBase
{
    /**
     * @param  list<string>  $inputKeys
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        private StepId $id,
        public string $operationId,
        public ?string $operationVersion,
        public array $inputKeys,
        public string $outputKey,
        public array $parameters = [],
    ) {
        foreach ([$operationId, $outputKey] as $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $value) !== 1) {
                throw new InvalidArgumentException("Invalid LLM operation or artifact key [{$value}].");
            }
        }
        foreach ($inputKeys as $key) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1) {
                throw new InvalidArgumentException("Invalid LLM operation input artifact key [{$key}].");
            }
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::operation();
    }

    public function artifactReads(): array
    {
        return $this->inputKeys;
    }

    public function artifactWrites(): array
    {
        return [$this->outputKey];
    }
}
