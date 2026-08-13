<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\ValueObject;

use InvalidArgumentException;

final class Identifier
{
    public const int MAX_CHARACTERS = 128;

    private function __construct() {}

    public static function normalize(string $value, string $name): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("{$name} must not be empty.");
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be valid UTF-8.");
        }

        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException(sprintf(
                '%s must contain at most %d Unicode characters.',
                $name,
                self::MAX_CHARACTERS,
            ));
        }

        return $value;
    }
}
