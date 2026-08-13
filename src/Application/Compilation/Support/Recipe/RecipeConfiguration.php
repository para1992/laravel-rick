<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Recipe;

use InvalidArgumentException;

final readonly class RecipeConfiguration
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Recipe configuration [{$key}] must be a non-empty string.");
        }

        return trim($value);
    }

    public function integer(string $key, int $default, int $minimum = 1): int
    {
        $value = $this->values[$key] ?? $default;
        if (! is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException("Recipe configuration [{$key}] must be >= {$minimum}.");
        }

        return $value;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Recipe configuration [{$key}] must be boolean.");
        }

        return $value;
    }
}
