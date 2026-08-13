<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Interface;

use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

interface ExecutionBackendBase
{
    public function continue(
        RunId $runId,
        int $transitionVersion,
        ?InvocationId $sourceInvocationId = null,
    ): void;

    public function invoke(InvocationId $invocationId, RunId $runId, int $transitionVersion): void;
}
