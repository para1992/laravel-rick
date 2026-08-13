<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

enum ProviderIdSource: string
{
    case Body = 'body';
    case Header = 'header';
    case Sdk = 'sdk';
    case Unavailable = 'unavailable';
}
