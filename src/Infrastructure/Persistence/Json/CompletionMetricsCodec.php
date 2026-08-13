<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use UnexpectedValueException;

final readonly class CompletionMetricsCodec
{
    private const int VERSION = 1;

    public function encode(CompletionMetrics $metrics): string
    {
        return json_encode([
            'schema_version' => self::VERSION,
            'metrics' => [
                'tokens' => $metrics->tokens->toArray(),
                'cost_usd' => $metrics->cost?->toUsdDecimal(),
                'latency_milliseconds' => $metrics->latencyMilliseconds,
                'provider_details' => $metrics->providerDetails,
                'provider_requests' => $metrics->providerRequests,
                'usage_complete' => $metrics->usageComplete,
                'usage_present' => $metrics->usagePresent,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): CompletionMetrics
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted completion metrics are not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted completion metrics must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'completion metrics envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported completion metrics schema version.');
        }
        $metrics = JsonInput::map($envelope['metrics'] ?? null, 'completion metrics');
        $tokens = JsonInput::map($metrics['tokens'] ?? null, 'completion metrics.tokens');
        $cost = JsonInput::nullableString($metrics['cost_usd'] ?? null, 'completion metrics.cost_usd');

        return new CompletionMetrics(
            new TokenUsage(
                JsonInput::integer($tokens['input_tokens'] ?? 0, 'completion metrics.tokens.input_tokens'),
                JsonInput::integer($tokens['output_tokens'] ?? 0, 'completion metrics.tokens.output_tokens'),
                JsonInput::nullableInteger($tokens['total_tokens'] ?? null, 'completion metrics.tokens.total_tokens'),
                JsonInput::integer($tokens['cached_input_tokens'] ?? 0, 'completion metrics.tokens.cached_input_tokens'),
                JsonInput::integer($tokens['cache_write_input_tokens'] ?? 0, 'completion metrics.tokens.cache_write_input_tokens'),
                JsonInput::integer($tokens['reasoning_tokens'] ?? 0, 'completion metrics.tokens.reasoning_tokens'),
            ),
            $cost === null ? null : InvocationCost::fromUsd($cost),
            JsonInput::nullableInteger(
                $metrics['latency_milliseconds'] ?? null,
                'completion metrics.latency_milliseconds',
            ),
            JsonInput::map($metrics['provider_details'] ?? [], 'completion metrics.provider_details'),
            JsonInput::integer($metrics['provider_requests'] ?? 1, 'completion metrics.provider_requests'),
            JsonInput::boolean($metrics['usage_complete'] ?? true, 'completion metrics.usage_complete'),
            JsonInput::boolean($metrics['usage_present'] ?? true, 'completion metrics.usage_present'),
        );
    }
}
