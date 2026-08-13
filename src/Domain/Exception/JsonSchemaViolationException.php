<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Exception;

use InvalidArgumentException;

final class JsonSchemaViolationException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $path,
        public readonly string $keyword,
    ) {
        parent::__construct(
            "JSON value at [{$path}] violates keyword [{$keyword}].",
        );
    }
}
