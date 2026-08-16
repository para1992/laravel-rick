<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class JudgeStep implements StepBase
{
    public function __construct(
        private StepId $id,
        public bool $automatic = false,
        public string $modelPolicyId = 'quality',
    ) {
        if (trim($modelPolicyId) === '') {
            throw new InvalidArgumentException('Judge model policy id must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::judge();
    }
}
