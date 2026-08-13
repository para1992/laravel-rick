<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Exception;

use RuntimeException;

final class WorkflowValidationException extends RuntimeException
{
    public function errorCode(): string
    {
        return 'workflow_validation_failed';
    }

    public function retryable(): bool
    {
        return false;
    }
}
