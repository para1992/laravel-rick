<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Request;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class GetRunTimelineRequest implements ExecutionRequestBase
{
    public function __construct(
        public RunId $runId,
        public int $afterVersion = 0,
    ) {
        if ($afterVersion < 0) {
            throw new InvalidArgumentException('Timeline version must not be negative.');
        }
    }
}
