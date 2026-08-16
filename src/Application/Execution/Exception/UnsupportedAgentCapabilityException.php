<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use RuntimeException;
use Throwable;

final class UnsupportedAgentCapabilityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $capability,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
