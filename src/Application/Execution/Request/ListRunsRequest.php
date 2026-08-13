<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Request;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Interface\ExecutionRequestBase;
use Rick\Laravel\Domain\Run\RunStatus;

final readonly class ListRunsRequest implements ExecutionRequestBase
{
    public function __construct(
        public ?string $cursor = null,
        public ?RunStatus $status = null,
        public int $limit = 25,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Run page limit must be between 1 and 100.');
        }
    }
}
