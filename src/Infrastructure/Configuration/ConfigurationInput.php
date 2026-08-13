<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\ValueObject\Identifier;

final readonly class ConfigurationInput
{
    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    public static function keys(array $value, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Unknown Rick configuration key [{$path}.{$unknown[0]}].",
            );
        }
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("Rick configuration [{$path}] must be an object.");
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "Rick configuration [{$path}] must use string keys.",
                );
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Rick configuration [{$path}] must be a list.");
        }

        return $value;
    }

    public static function string(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Rick configuration [{$path}] must be a non-empty string.",
            );
        }

        return trim($value);
    }

    public static function nullableString(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::string($value, $path);
    }

    public static function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Rick configuration [{$path}] must be boolean.");
        }

        return $value;
    }

    public static function integer(mixed $value, string $path, int $minimum, ?int $maximum = null): int
    {
        if (! is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            $range = $maximum === null ? "at least {$minimum}" : "between {$minimum} and {$maximum}";
            throw new InvalidArgumentException(
                "Rick configuration [{$path}] must be an integer {$range}.",
            );
        }

        return $value;
    }

    /** @return list<int> */
    public static function integerList(mixed $value, string $path, int $minimum = 0): array
    {
        $result = [];
        foreach (self::list($value, $path) as $index => $item) {
            $result[] = self::integer($item, "{$path}.{$index}", $minimum);
        }

        return $result;
    }

    /** @return list<string> */
    public static function stringList(mixed $value, string $path): array
    {
        $result = [];
        foreach (self::list($value, $path) as $index => $item) {
            $result[] = self::string($item, "{$path}.{$index}");
        }

        return $result;
    }

    public static function identifier(mixed $value, string $path): string
    {
        try {
            return Identifier::normalize(self::string($value, $path), $path);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException(
                "Rick configuration [{$path}] is not a valid identifier.",
                previous: $error,
            );
        }
    }

    public static function table(mixed $value, string $path): string
    {
        $name = self::string($value, $path);
        if (strlen($name) > 63 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "Rick configuration [{$path}] must be a portable table name of at most 63 bytes.",
            );
        }

        return $name;
    }

    public static function queueName(mixed $value, string $path): string
    {
        $name = self::string($value, $path);
        if (strlen($name) > 128 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Rick configuration [{$path}] is not a valid queue name.");
        }

        return $name;
    }

    public static function decimal(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Rick configuration [{$path}] must be a decimal string.");
        }

        try {
            InvocationCost::fromUsd($value);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException(
                "Rick configuration [{$path}] must be a non-negative decimal string.",
                previous: $error,
            );
        }

        return $value;
    }
}
