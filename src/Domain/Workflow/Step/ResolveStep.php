<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class ResolveStep implements StepBase
{
    public function __construct(
        private StepId $id,
        public string $task,
        public DefinitionOfDone $dod,
    ) {}

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::resolve();
    }
}
