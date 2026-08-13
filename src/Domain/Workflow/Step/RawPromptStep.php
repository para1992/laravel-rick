<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow\Step;

use InvalidArgumentException;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Interface\TerminalStepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class RawPromptStep implements StepBase, TerminalStepBase
{
    public function __construct(
        private StepId $id,
        public string $prompt,
        public string $modelPolicyId = 'default',
    ) {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('RAW_PROMPT prompt must not be empty.');
        }
        if (trim($modelPolicyId) === '') {
            throw new InvalidArgumentException('RAW_PROMPT model policy id must not be empty.');
        }
    }

    public function id(): StepId
    {
        return $this->id;
    }

    public function type(): StepType
    {
        return StepType::rawPrompt();
    }
}
