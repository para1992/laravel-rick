<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use RuntimeException;
use Throwable;

final class StructuredResponseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly StructuredResponseDiagnostic $diagnostic,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
