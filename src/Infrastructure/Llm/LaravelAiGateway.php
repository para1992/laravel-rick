<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Execution\Support\Schema\StructuredResponseDecoder;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Throwable;
use UnexpectedValueException;

final readonly class LaravelAiGateway implements GatewayBase
{
    public function __construct(
        private ModelRouter $models,
        private PromptMapper $prompts,
        private PricingBase $pricing,
        private int $timeout = 60,
        private LaravelAiFailureClassifier $failures = new LaravelAiFailureClassifier,
        private ?ResponseSchemaResolver $responseSchemas = null,
        private ?StructuredResponseDecoder $structuredResponses = null,
    ) {}

    public function complete(CompletionRequest $request): CompletionResponse
    {
        try {
            $route = $this->models->route($request->modelTier);
            $prompt = $this->prompts->map($request);
            $options = GenerationOptions::from($request->options, $this->timeout);
            $agent = $request->responseContract === ResponseContract::Text
                ? new TextAgent(
                    $prompt['instructions'],
                    $prompt['history'],
                    options: $options,
                )
                : new StructuredAgent(
                    $prompt['instructions'],
                    $prompt['history'],
                    schema: $this->responseSchemas()->for($request),
                    options: $options,
                );
        } catch (Throwable $failure) {
            throw $this->failures->preflight($failure);
        }
        $started = hrtime(true);
        try {
            $response = $agent->prompt(
                $prompt['prompt'],
                provider: $route['provider'],
                model: $route['model'],
                timeout: $options->timeout,
            );
        } catch (Throwable $failure) {
            $classified = $this->failures->classify($failure);
            $provider = self::routeProvider($route['provider'] ?? null);
            $model = is_string($route['model'] ?? null) ? $route['model'] : 'unknown';
            $identifiers = $classified->identifiers;
            if ($identifiers === null && $classified->requestId !== null) {
                $identifiers = new ProviderIdentifiers(
                    null,
                    $classified->requestId,
                    null,
                    ProviderIdSource::Header,
                );
            }

            throw new ProviderRequestException(
                $classified->safeCode,
                $classified->safeMessage,
                $classified->retryable,
                $classified->outcome,
                $classified->requestId,
                $classified->metrics ?? new CompletionMetrics(
                    TokenUsage::zero(),
                    null,
                    latencyMilliseconds: max(0, (int) ((hrtime(true) - $started) / 1_000_000)),
                    providerDetails: [],
                    providerRequests: 1,
                    usageComplete: false,
                    usagePresent: false,
                ),
                $classified,
                $classified->httpStatusClass,
                $identifiers,
                $classified->diagnostic,
                $provider,
                $model,
                $provider.':'.$model,
                $request->modelTier,
            );
        }
        $usage = $response->usage;

        $tokens = new TokenUsage(
            $usage->promptTokens,
            $usage->completionTokens,
            cachedInputTokens: $usage->cacheReadInputTokens,
            cacheWriteInputTokens: $usage->cacheWriteInputTokens,
            reasoningTokens: $usage->reasoningTokens,
        );
        $provider = $response->meta->provider ?? 'laravel-ai';
        $model = $response->meta->model ?? ($route['model'] ?? 'default');
        $usagePresent = self::usagePresent($tokens);
        $finishReason = self::finishReason($response);
        $inspection = $response instanceof StructuredAgentResponse
            ? $this->structuredResponses()->decode(
                $request,
                $response->text,
                $finishReason,
                $usagePresent,
                $usagePresent,
            )
            : null;
        $providerRequestId = self::metaIdentifier($response->meta, [
            'requestId',
            'request_id',
        ]);
        $providerGenerationId = self::metaIdentifier($response->meta, [
            'generationId',
            'generation_id',
        ]);

        return new CompletionResponse(
            $response->text,
            $inspection?->value,
            $provider,
            $model,
            [
                'gateway_invocation_id' => $response->invocationId,
                'provider_request_id' => $providerRequestId,
                'provider_generation_id' => $providerGenerationId,
                'provider_id_source' => $providerRequestId !== null || $providerGenerationId !== null
                    ? 'sdk'
                    : 'unavailable',
                'resolved_route' => $provider.':'.$model,
                'model_tier' => $request->modelTier,
                'finish_reason' => $finishReason,
            ],
            new CompletionMetrics(
                $tokens,
                $this->pricing->actual($provider, $model, $tokens),
                latencyMilliseconds: max(0, (int) ((hrtime(true) - $started) / 1_000_000)),
                providerDetails: array_filter([
                    'finish_reason' => $finishReason,
                ], static fn (mixed $value): bool => $value !== null),
                usageComplete: $usagePresent,
                usagePresent: $usagePresent,
            ),
            $inspection?->diagnostic,
        );
    }

    private function responseSchemas(): ResponseSchemaResolver
    {
        return $this->responseSchemas
            ?? throw new UnexpectedValueException('Structured output schema validation is not configured.');
    }

    private function structuredResponses(): StructuredResponseDecoder
    {
        return $this->structuredResponses
            ?? throw new UnexpectedValueException('Structured response decoding is not configured.');
    }

    private static function usagePresent(TokenUsage $tokens): bool
    {
        return $tokens->inputTokens > 0
            || $tokens->outputTokens > 0
            || $tokens->cachedInputTokens > 0
            || $tokens->cacheWriteInputTokens > 0
            || $tokens->reasoningTokens > 0;
    }

    private static function finishReason(AgentResponse $response): ?string
    {
        $step = $response->steps->last();
        if (! $step instanceof Step) {
            return null;
        }

        return $step->finishReason->value;
    }

    /** @param list<string> $keys */
    private static function metaIdentifier(object $meta, array $keys): ?string
    {
        $values = get_object_vars($meta);
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (
                is_string($value)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+=-]{0,127}$/D', $value) === 1
            ) {
                return $value;
            }
        }

        return null;
    }

    private static function routeProvider(mixed $provider): string
    {
        if (is_string($provider) && trim($provider) !== '') {
            return $provider;
        }
        if (is_array($provider)) {
            $names = array_values(array_filter(
                array_keys($provider),
                static fn (mixed $name): bool => is_string($name) && $name !== '',
            ));
            if ($names !== []) {
                return implode('|', $names);
            }
        }

        return 'unknown';
    }
}
