<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Interface;

use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

interface StepBase
{
    public function id(): StepId;

    public function type(): StepType;
}
