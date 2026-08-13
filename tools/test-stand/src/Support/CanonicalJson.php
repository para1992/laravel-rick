<?php

declare(strict_types=1);

namespace Rick\Stand\Support;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }

        return $value;
    }
}
