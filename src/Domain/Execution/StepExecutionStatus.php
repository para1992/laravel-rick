<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

enum StepExecutionStatus: string
{
    case Waiting = 'waiting';
    case AwaitingInput = 'awaiting_input';
    case Reducing = 'reducing';
    case Continuing = 'continuing';
    case Completed = 'completed';
    case Failed = 'failed';
}
