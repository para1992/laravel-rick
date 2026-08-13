<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use Rick\Laravel\Application\Execution\Interface\StepFailureBase;
use RuntimeException;

final class GroundedVerificationFailedException extends RuntimeException implements StepFailureBase
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            'Grounded verification failed: '.implode('; ', $violations),
        );
    }

    public function errorCode(): string
    {
        return 'grounded_verification_failed';
    }
}
