<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Result;

use Rick\Laravel\Application\Execution\Interface\ExecutionResultBase;
use Rick\Laravel\Domain\Run\RunPage;

final readonly class ListRunsResult implements ExecutionResultBase
{
    public function __construct(public RunPage $page) {}
}
