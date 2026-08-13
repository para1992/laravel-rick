<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

final class InvalidStateTransitionException extends ExceptionBase
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'invalid_state_transition');
    }
}
