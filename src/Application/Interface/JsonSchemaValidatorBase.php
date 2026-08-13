<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

interface JsonSchemaValidatorBase
{
    /** @param array<string, mixed> $schema */
    public function assertSchema(array $schema): void;

    /** @param array<string, mixed> $schema */
    public function assert(array $schema, mixed $value): void;
}
