<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Outbox;

use UnexpectedValueException;

final readonly class OutboxRecord
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $runId,
        public ?string $invocationId,
        public ?string $eventType,
        public ?string $payload,
        public int $attempts,
        public int $version,
        public string $leaseToken,
    ) {}

    public static function fromRow(object $row): self
    {
        $data = self::map(get_object_vars($row));

        return new self(
            self::string($data, 'id'),
            self::string($data, 'kind'),
            self::string($data, 'run_id'),
            self::nullableString($data, 'invocation_id'),
            self::nullableString($data, 'event_type'),
            self::nullableString($data, 'payload'),
            self::integer($data, 'attempts'),
            self::integer($data, 'version'),
            self::string($data, 'lease_token'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("Outbox field [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException("Outbox field [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new UnexpectedValueException("Outbox field [{$key}] must be an integer.");
        }

        return (int) $value;
    }

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Outbox row must use string column names.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
