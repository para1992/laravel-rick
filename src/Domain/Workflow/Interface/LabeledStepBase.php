<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Interface;

interface LabeledStepBase extends StepBase
{
    public function label(): ?string;
}
