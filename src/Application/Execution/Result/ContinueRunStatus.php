<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Result;

enum ContinueRunStatus: string
{
    case Continue = 'continue';
    case Dispatch = 'dispatch';
    case Waiting = 'waiting';
    case Terminal = 'terminal';
}
