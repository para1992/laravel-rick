<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use UnexpectedValueException;

/** Strict access to values decoded from persisted JSON. */
final readonly class JsonInput
{
    /** @return array<array-key, mixed> */
    public static function valueArray(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException("Persisted [{$path}] must be an array.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new UnexpectedValueException("Persisted [{$path}] must be an object.");
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException("Persisted [{$path}] must use string keys.");
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("Persisted [{$path}] must be a list.");
        }

        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value, string $path): array
    {
        $strings = [];
        foreach (self::list($value, $path) as $index => $item) {
            $strings[] = self::string($item, "{$path}.{$index}");
        }

        return $strings;
    }

    public static function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException("Persisted [{$path}] must be a string.");
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::string($value, $path);
    }

    public static function integer(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            throw new UnexpectedValueException("Persisted [{$path}] must be an integer.");
        }

        return $value;
    }

    public static function nullableInteger(mixed $value, string $path): ?int
    {
        return $value === null ? null : self::integer($value, $path);
    }

    public static function number(mixed $value, string $path): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new UnexpectedValueException("Persisted [{$path}] must be numeric.");
        }

        return is_int($value) ? $value + 0.0 : $value;
    }

    public static function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new UnexpectedValueException("Persisted [{$path}] must be a boolean.");
        }

        return $value;
    }
}
