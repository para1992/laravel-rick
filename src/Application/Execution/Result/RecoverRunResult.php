<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Result;

use Rick\Laravel\Application\Execution\Interface\ExecutionResultBase;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;

final readonly class RecoverRunResult implements ExecutionResultBase
{
    public function __construct(
        public WorkflowRunSnapshot $run,
        public int $reusedInvocations,
        public int $queuedInvocations,
        public int $copiedFailures,
        public bool $alreadyExists = false,
    ) {}
}
