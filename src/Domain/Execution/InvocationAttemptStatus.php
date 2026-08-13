<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

enum InvocationAttemptStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';
}
