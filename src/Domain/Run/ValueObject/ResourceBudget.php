<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run\ValueObject;

use InvalidArgumentException;
use JsonSerializable;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;

final readonly class ResourceBudget implements JsonSerializable
{
    public function __construct(
        public ?int $maxInputTokens = null,
        public ?int $maxOutputTokens = null,
        public ?int $maxTotalTokens = null,
        public ?InvocationCost $maxCost = null,
        public ?int $maxLatencyMilliseconds = null,
        public ?int $maxDurationMilliseconds = null,
        public int $defaultOutputReservationTokens = 2048,
        public bool $requireCompleteMetrics = false,
        public bool $requireKnownPricing = true,
    ) {
        foreach ([
            'maxInputTokens' => $maxInputTokens,
            'maxOutputTokens' => $maxOutputTokens,
            'maxTotalTokens' => $maxTotalTokens,
            'maxLatencyMilliseconds' => $maxLatencyMilliseconds,
            'maxDurationMilliseconds' => $maxDurationMilliseconds,
        ] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("{$name} must be positive when configured.");
            }
        }

        if ($defaultOutputReservationTokens < 1) {
            throw new InvalidArgumentException('Default output reservation must be positive.');
        }
    }

    public static function unbounded(): self
    {
        return new self;
    }

    public function isUnbounded(): bool
    {
        return $this->maxInputTokens === null
            && $this->maxOutputTokens === null
            && $this->maxTotalTokens === null
            && $this->maxCost === null
            && $this->maxLatencyMilliseconds === null
            && $this->maxDurationMilliseconds === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'max_input_tokens' => $this->maxInputTokens,
            'max_output_tokens' => $this->maxOutputTokens,
            'max_total_tokens' => $this->maxTotalTokens,
            'max_cost_usd' => $this->maxCost?->toUsdDecimal(),
            'max_latency_milliseconds' => $this->maxLatencyMilliseconds,
            'max_duration_milliseconds' => $this->maxDurationMilliseconds,
            'default_output_reservation_tokens' => $this->defaultOutputReservationTokens,
            'require_complete_metrics' => $this->requireCompleteMetrics,
            'require_known_pricing' => $this->requireKnownPricing,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cost = $data['max_cost_usd'] ?? null;
        if ($cost !== null && ! is_string($cost)) {
            throw new InvalidArgumentException('Resource budget cost must be a decimal string or null.');
        }

        return new self(
            self::nullableInteger($data, 'max_input_tokens'),
            self::nullableInteger($data, 'max_output_tokens'),
            self::nullableInteger($data, 'max_total_tokens'),
            $cost === null ? null : InvocationCost::fromUsd($cost),
            self::nullableInteger($data, 'max_latency_milliseconds'),
            self::nullableInteger($data, 'max_duration_milliseconds'),
            self::integer($data, 'default_output_reservation_tokens', 2048),
            self::boolean($data, 'require_complete_metrics', false),
            self::boolean($data, 'require_known_pricing', true),
        );
    }

    /** @param array<string, mixed> $data */
    private static function nullableInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw new InvalidArgumentException("Resource budget field [{$key}] must be an integer or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;
        if (! is_int($value)) {
            throw new InvalidArgumentException("Resource budget field [{$key}] must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Resource budget field [{$key}] must be a boolean.");
        }

        return $value;
    }
}
