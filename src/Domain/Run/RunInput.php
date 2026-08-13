<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

use InvalidArgumentException;

final readonly class RunInput
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private array $values,
    ) {}

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Run input [{$key}] is missing.");
        }

        return $this->values[$key];
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        if (! is_string($value)) {
            throw new InvalidArgumentException("Run input [{$key}] must be a string.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
