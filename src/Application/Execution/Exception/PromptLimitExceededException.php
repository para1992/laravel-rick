<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use Rick\Laravel\Application\Execution\Interface\StepFailureBase;
use RuntimeException;

final class PromptLimitExceededException extends RuntimeException implements StepFailureBase
{
    public function errorCode(): string
    {
        return 'prompt_limit_exceeded';
    }
}
