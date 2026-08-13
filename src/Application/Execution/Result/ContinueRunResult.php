<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Result;

use Rick\Laravel\Application\Execution\Interface\ExecutionResultBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class ContinueRunResult implements ExecutionResultBase
{
    /** @param list<InvocationId> $invocations */
    public function __construct(
        public ContinueRunStatus $status,
        public WorkflowRunSnapshot $run,
        public array $invocations = [],
    ) {}
}
