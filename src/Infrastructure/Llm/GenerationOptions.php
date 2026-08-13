<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use InvalidArgumentException;

final readonly class GenerationOptions
{
    /** @param array<string, mixed> $provider */
    public function __construct(
        public ?int $maxTokens,
        public ?float $temperature,
        public ?float $topP,
        public int $timeout,
        public array $provider,
    ) {}

    /** @param array<string, mixed> $options */
    public static function from(array $options, int $timeout): self
    {
        $provider = $options;
        foreach (['max_tokens', 'temperature', 'top_p', 'timeout'] as $key) {
            unset($provider[$key]);
        }

        return new self(
            self::optionalPositiveInteger($options, 'max_tokens'),
            self::optionalNumber($options, 'temperature'),
            self::optionalNumber($options, 'top_p'),
            self::positiveInteger($options['timeout'] ?? $timeout, 'timeout'),
            $provider,
        );
    }

    /** @param array<string, mixed> $options */
    private static function optionalPositiveInteger(array $options, string $key): ?int
    {
        return array_key_exists($key, $options)
            ? self::positiveInteger($options[$key], $key)
            : null;
    }

    private static function positiveInteger(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Generation option [{$key}] must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function optionalNumber(array $options, string $key): ?float
    {
        if (! array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Generation option [{$key}] must be numeric.");
        }

        return is_int($value) ? $value + 0.0 : $value;
    }
}
