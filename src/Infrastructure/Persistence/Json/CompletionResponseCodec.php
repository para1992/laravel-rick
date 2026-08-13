<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Persistence\Json;

use JsonException;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use UnexpectedValueException;

final readonly class CompletionResponseCodec
{
    private const int VERSION = 1;

    public function __construct(private StructuredResponseDiagnosticCodec $diagnostics) {}

    public function encode(CompletionResponse $response): string
    {
        return json_encode([
            'schema_version' => self::VERSION,
            'response' => [
                'text' => $response->text,
                'structured' => $response->structured,
                'provider' => $response->provider,
                'model' => $response->model,
                'metadata' => $response->metadata,
                'metrics' => $response->metrics === null ? null : [
                    'tokens' => $response->metrics->tokens->toArray(),
                    'cost_usd' => $response->metrics->cost?->toUsdDecimal(),
                    'latency_milliseconds' => $response->metrics->latencyMilliseconds,
                    'provider_details' => $response->metrics->providerDetails,
                    'provider_requests' => $response->metrics->providerRequests,
                    'usage_complete' => $response->metrics->usageComplete,
                    'usage_present' => $response->metrics->usagePresent,
                ],
                'diagnostic' => $response->diagnostic?->toArray(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $payload): CompletionResponse
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Persisted completion response is not valid JSON.', previous: $error);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Persisted completion response must be an object.');
        }
        $envelope = JsonInput::map($decoded, 'completion response envelope');
        if (($envelope['schema_version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Unsupported completion response schema version.');
        }
        $data = JsonInput::map($envelope['response'] ?? null, 'completion response');
        $metrics = $data['metrics'] ?? null;
        $structured = $data['structured'] ?? null;

        return new CompletionResponse(
            JsonInput::string($data['text'] ?? null, 'completion response.text'),
            $structured === null
                ? null
                : JsonInput::map($structured, 'completion response.structured'),
            JsonInput::string($data['provider'] ?? null, 'completion response.provider'),
            JsonInput::string($data['model'] ?? null, 'completion response.model'),
            JsonInput::map($data['metadata'] ?? [], 'completion response.metadata'),
            $metrics === null
                ? null
                : self::metrics(JsonInput::map($metrics, 'completion response.metrics')),
            ($data['diagnostic'] ?? null) === null
                ? null
                : $this->diagnostics->decodeArray(JsonInput::map(
                    $data['diagnostic'],
                    'completion response.diagnostic',
                )),
        );
    }

    /** @param array<string, mixed> $data */
    private static function metrics(array $data): CompletionMetrics
    {
        $cost = JsonInput::nullableString(
            $data['cost_usd'] ?? null,
            'completion response.metrics.cost_usd',
        );

        return new CompletionMetrics(
            self::tokens(JsonInput::map(
                $data['tokens'] ?? null,
                'completion response.metrics.tokens',
            )),
            $cost === null ? null : InvocationCost::fromUsd($cost),
            JsonInput::nullableInteger(
                $data['latency_milliseconds'] ?? null,
                'completion response.metrics.latency_milliseconds',
            ),
            JsonInput::map(
                $data['provider_details'] ?? [],
                'completion response.metrics.provider_details',
            ),
            JsonInput::integer(
                $data['provider_requests'] ?? 1,
                'completion response.metrics.provider_requests',
            ),
            JsonInput::boolean(
                $data['usage_complete'] ?? true,
                'completion response.metrics.usage_complete',
            ),
            JsonInput::boolean(
                $data['usage_present'] ?? true,
                'completion response.metrics.usage_present',
            ),
        );
    }

    /** @param array<string, mixed> $data */
    private static function tokens(array $data): TokenUsage
    {
        return new TokenUsage(
            JsonInput::integer($data['input_tokens'] ?? 0, 'completion response.tokens.input_tokens'),
            JsonInput::integer($data['output_tokens'] ?? 0, 'completion response.tokens.output_tokens'),
            JsonInput::nullableInteger($data['total_tokens'] ?? null, 'completion response.tokens.total_tokens'),
            JsonInput::integer($data['cached_input_tokens'] ?? 0, 'completion response.tokens.cached_input_tokens'),
            JsonInput::integer($data['cache_write_input_tokens'] ?? 0, 'completion response.tokens.cache_write_input_tokens'),
            JsonInput::integer($data['reasoning_tokens'] ?? 0, 'completion response.tokens.reasoning_tokens'),
        );
    }
}
