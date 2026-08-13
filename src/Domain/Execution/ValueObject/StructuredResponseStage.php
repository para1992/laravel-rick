<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

enum StructuredResponseStage: string
{
    case Decode = 'decode';
    case SchemaValidation = 'schema_validation';
}
