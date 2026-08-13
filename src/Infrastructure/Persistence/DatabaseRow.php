<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use UnexpectedValueException;

/** Strict, driver-neutral access to one Query Builder result row. */
final readonly class DatabaseRow
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    public static function from(object $row): self
    {
        $values = [];
        foreach (get_object_vars($row) as $column => $value) {
            if (! is_string($column)) {
                throw new UnexpectedValueException('Database row contains an invalid column name.');
            }
            $values[$column] = $value;
        }

        return new self($values);
    }

    public function value(string $column): mixed
    {
        if (! array_key_exists($column, $this->values)) {
            throw new UnexpectedValueException("Database row has no [{$column}] column.");
        }

        return $this->values[$column];
    }

    public function valueOr(string $column, mixed $default = null): mixed
    {
        return $this->values[$column] ?? $default;
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->values);
    }

    public function string(string $column): string
    {
        $value = $this->value($column);
        if (! is_string($value)) {
            throw new UnexpectedValueException("Database column [{$column}] must be a string.");
        }

        return $value;
    }

    public function nullableString(string $column): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : self::stringValue($value, $column);
    }

    public function nullableStringOr(string $column, ?string $default = null): ?string
    {
        return $this->has($column) ? $this->nullableString($column) : $default;
    }

    public function integer(string $column): int
    {
        return self::integerValue($this->value($column), $column);
    }

    public function integerOr(string $column, int $default): int
    {
        return $this->has($column) ? $this->integer($column) : $default;
    }

    public static function integerValue(mixed $value, string $name, ?int $default = null): int
    {
        if ($value === null && $default !== null) {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new UnexpectedValueException("Database value [{$name}] must be an integer.");
    }

    public function timestamp(string $column): DateTimeImmutable
    {
        $value = $this->value($column);
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value)) {
            throw new UnexpectedValueException("Database column [{$column}] must be a timestamp.");
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $error) {
            throw new UnexpectedValueException(
                "Database column [{$column}] contains an invalid timestamp.",
                previous: $error,
            );
        }
    }

    public function nullableTimestamp(string $column): ?DateTimeImmutable
    {
        return $this->value($column) === null ? null : $this->timestamp($column);
    }

    private static function stringValue(mixed $value, string $column): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException("Database column [{$column}] must be a string or null.");
        }

        return $value;
    }
}
