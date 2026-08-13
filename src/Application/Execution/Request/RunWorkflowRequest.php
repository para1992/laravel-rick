<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Request;

use DateTimeImmutable;
use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class RunWorkflowRequest implements ExecutionRequestBase
{
    public function __construct(
        public RunId $runId,
        public RunInput $input,
        public int $callLimit = 60,
        public ?DateTimeImmutable $startedAt = null,
    ) {}
}
