<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Interface;

use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;

interface RunRepositoryBase
{
    public function add(WorkflowRun $run): void;

    public function addRecovery(WorkflowRun $run): bool;

    public function findRecovery(RunId $parentRunId, RunRecoveryAction $action): ?WorkflowRun;

    public function get(RunId $id): WorkflowRun;

    public function save(WorkflowRun $run, int $expectedVersion): void;
}
