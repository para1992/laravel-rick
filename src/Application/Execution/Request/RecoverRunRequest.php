<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Request;

use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class RecoverRunRequest implements ExecutionRequestBase
{
    public function __construct(
        public RunId $parentRunId,
        public RunId $childRunId,
        public RunRecoveryAction $action,
        public ?int $callLimit = null,
    ) {}
}
