<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Request;

use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class SubmitInputRequest implements ExecutionRequestBase
{
    public function __construct(
        public RunId $runId,
        public string $key,
        public mixed $value,
    ) {}
}
