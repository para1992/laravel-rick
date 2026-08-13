<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

enum InvocationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Indeterminate = 'indeterminate';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
