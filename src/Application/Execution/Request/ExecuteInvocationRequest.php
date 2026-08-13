<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Request;

use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;

final readonly class ExecuteInvocationRequest implements ExecutionRequestBase
{
    public function __construct(public InvocationId $invocationId) {}
}
