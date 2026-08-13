<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Result;

use Rick\Laravel\Application\Execution\Interface\ExecutionResultBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;

final readonly class ExecuteInvocationResult implements ExecutionResultBase
{
    public function __construct(public InvocationId $invocationId) {}
}
