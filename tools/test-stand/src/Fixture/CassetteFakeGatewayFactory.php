<?php

declare(strict_types=1);

namespace Rick\Stand\Fixture;

use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Testing\FakeGateway;
use RuntimeException;

final class CassetteFakeGatewayFactory
{
    /** @param list<string> $ids */
    public function make(CassetteCatalog $catalog, array $ids): FakeGateway
    {
        $cassettes = array_map($catalog->get(...), $ids);

        return (new FakeGateway)->respondUsing(
            static function (CompletionRequest $request) use ($cassettes): CompletionResponse {
                foreach ($cassettes as $cassette) {
                    if (! $cassette->matches($request)) {
                        continue;
                    }
                    if ($cassette->outcome['type'] === 'provider_error') {
                        $error = $cassette->outcome['error'];
                        throw new ProviderRequestException(
                            safeCode: $error['safe_code'],
                            safeMessage: $error['safe_message'],
                            retryable: $error['retryable'],
                            outcome: ProviderRequestOutcome::from($error['request_outcome']),
                            requestId: $error['request_id'] ?? null,
                            httpStatusClass: $error['http_status_class'] ?? null,
                        );
                    }
                    $response = $cassette->outcome['response'];
                    $metrics = $cassette->metrics;

                    return new CompletionResponse(
                        text: $response['text'],
                        structured: $response['structured'],
                        provider: $response['provider'],
                        model: $response['model'],
                        metrics: new CompletionMetrics(
                            tokens: new TokenUsage(
                                $metrics['input_tokens'],
                                $metrics['output_tokens'],
                                cachedInputTokens: $metrics['cached_input_tokens'],
                                cacheWriteInputTokens: $metrics['cache_write_input_tokens'],
                                reasoningTokens: $metrics['reasoning_tokens'],
                            ),
                            cost: InvocationCost::fromUsd($metrics['cost_usd']),
                            latencyMilliseconds: $metrics['latency_milliseconds'],
                            providerRequests: $metrics['provider_requests'],
                            usageComplete: $metrics['usage_complete'],
                            usagePresent: $metrics['usage_present'],
                        ),
                    );
                }

                throw new RuntimeException(sprintf(
                    'No cassette matched purpose [%s] and contract [%s].',
                    $request->purpose,
                    $request->responseContract->value,
                ));
            },
        );
    }
}
