<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

enum ProviderRequestOutcome: string
{
    case NotAccepted = 'not_accepted';
    case ResponseReceived = 'response_received';
    case Indeterminate = 'indeterminate';
}
