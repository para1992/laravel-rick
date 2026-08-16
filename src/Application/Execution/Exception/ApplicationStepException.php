<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use Rick\Laravel\Application\Execution\Interface\StepFailureBase;
use RuntimeException;

final class ApplicationStepException extends RuntimeException implements StepFailureBase
{
    public function errorCode(): string
    {
        return 'application_step_failed';
    }
}
