<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

enum RunRecoveryAction: string
{
    case RetryFailed = 'retry_failed';
    case ContinueSuccessful = 'continue_successful';
    case ForkFailedStep = 'fork_failed_step';
}
