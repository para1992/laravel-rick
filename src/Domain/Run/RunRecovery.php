<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use JsonSerializable;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class RunRecovery implements JsonSerializable
{
    public function __construct(
        public RunId $parentRunId,
        public RunRecoveryAction $action,
        public StepId $stepId,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'parent_run_id' => $this->parentRunId->toString(),
            'action' => $this->action->value,
            'step_id' => $this->stepId->toString(),
        ];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
