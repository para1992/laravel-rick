<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Event;

trait HasDeterministicEventId
{
    public function eventId(): string
    {
        return hash('sha256', static::class."\0".json_encode(
            self::normalize(get_object_vars($this)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_object($value) && method_exists($value, 'toString')) {
            return $value->toString();
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return self::normalize($value->toArray());
        }
        if (is_object($value)) {
            return self::normalize(get_object_vars($value));
        }

        return $value;
    }
}
