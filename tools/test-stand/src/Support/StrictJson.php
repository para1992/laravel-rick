<?php

declare(strict_types=1);

namespace Rick\Stand\Support;

use InvalidArgumentException;

final class StrictJson
{
    /** @return array<string, mixed> */
    public static function file(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Unable to read JSON file [{$path}].");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException("JSON file [{$path}] must contain an object.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    public static function keys(array $value, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s contains unknown field(s): %s.',
                $path,
                implode(', ', $unknown),
            ));
        }
    }

    public static function string(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$path} must be a non-empty string.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }

        return $value;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$path} must be a list.");
        }

        return $value;
    }
}
