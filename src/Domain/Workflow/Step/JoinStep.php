<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\ArtifactStepBase;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class JoinStep implements ArtifactStepBase, StepBase
{
    /** @param list<string> $inputKeys */
    public function __construct(
        private StepId $id,
        public array $inputKeys,
        public string $outputKey,
        public string $mode = 'concat',
        public string $separator = "\n\n",
    ) {
        if ($inputKeys === [] || ! in_array($mode, ['concat', 'array'], true)) {
            throw new InvalidArgumentException('Join requires inputs and concat or array mode.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::join();
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
