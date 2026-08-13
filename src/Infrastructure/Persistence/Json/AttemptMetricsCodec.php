<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use UnexpectedValueException;

final readonly class AttemptMetricsCodec
{
    private const int VERSION = 1;

    public function encode(AttemptMetrics $metrics): string
    {
        return json_encode([
            'schema_version' => self::VERSION,
            'metrics' => $metrics->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): AttemptMetrics
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted attempt metrics are not valid JSON.', previous: $error);
        }
        $envelope = JsonInput::map($decoded, 'attempt metrics envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported attempt metrics schema version.');
        }
        $data = JsonInput::map($envelope['metrics'] ?? null, 'attempt metrics');
        if (($data['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported attempt metrics data version.');
        }
        $tokens = JsonInput::map($data['tokens'] ?? null, 'attempt metrics.tokens');
        $cost = JsonInput::nullableString($data['cost_usd'] ?? null, 'attempt metrics.cost_usd');

        return new AttemptMetrics(
            JsonInput::string($data['provider'] ?? null, 'attempt metrics.provider'),
            JsonInput::string($data['model'] ?? null, 'attempt metrics.model'),
            JsonInput::string($data['resolved_route'] ?? null, 'attempt metrics.resolved_route'),
            JsonInput::string($data['model_tier'] ?? null, 'attempt metrics.model_tier'),
            new TokenUsage(
                JsonInput::integer($tokens['input_tokens'] ?? 0, 'attempt metrics.tokens.input_tokens'),
                JsonInput::integer($tokens['output_tokens'] ?? 0, 'attempt metrics.tokens.output_tokens'),
                JsonInput::nullableInteger($tokens['total_tokens'] ?? null, 'attempt metrics.tokens.total_tokens'),
                JsonInput::integer($tokens['cached_input_tokens'] ?? 0, 'attempt metrics.tokens.cached_input_tokens'),
                JsonInput::integer($tokens['cache_write_input_tokens'] ?? 0, 'attempt metrics.tokens.cache_write_input_tokens'),
                JsonInput::integer($tokens['reasoning_tokens'] ?? 0, 'attempt metrics.tokens.reasoning_tokens'),
            ),
            $cost === null ? null : InvocationCost::fromUsd($cost),
            JsonInput::nullableInteger($data['latency_milliseconds'] ?? null, 'attempt metrics.latency_milliseconds'),
            JsonInput::integer($data['provider_requests'] ?? null, 'attempt metrics.provider_requests'),
            JsonInput::boolean($data['usage_present'] ?? null, 'attempt metrics.usage_present'),
            JsonInput::boolean($data['usage_complete'] ?? null, 'attempt metrics.usage_complete'),
            JsonInput::integer($data['prompt_characters'] ?? null, 'attempt metrics.prompt_characters'),
            JsonInput::integer($data['response_characters'] ?? null, 'attempt metrics.response_characters'),
        );
    }
}
