<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

use RuntimeException;

abstract class ExceptionBase extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
