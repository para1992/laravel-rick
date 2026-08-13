<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

enum InvocationCompletionMode: string
{
    case AllRequired = 'all_required';
    case MinimumSuccessful = 'minimum_successful';
}
