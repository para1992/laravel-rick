<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Interface;

interface StepFailureBase
{
    public function errorCode(): string;

    public function getMessage(): string;
}
