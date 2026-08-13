<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

enum StructuredDecodeStatus: string
{
    case Empty = 'empty';
    case InvalidJson = 'invalid_json';
    case Scalar = 'scalar';
    case Array = 'array';
    case Object = 'object';
}
