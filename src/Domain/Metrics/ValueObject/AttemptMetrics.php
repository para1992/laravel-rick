<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

final readonly class AttemptMetrics implements JsonSerializable
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $resolvedRoute,
        public string $modelTier,
        public TokenUsage $tokens,
        public ?InvocationCost $cost,
        public ?int $latencyMilliseconds,
        public int $providerRequests,
        public bool $usagePresent,
        public bool $usageComplete,
        public int $promptCharacters,
        public int $responseCharacters,
    ) {
        foreach ([$provider, $model, $resolvedRoute, $modelTier] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Attempt metric route values must not be empty.');
            }
        }
        if ($latencyMilliseconds !== null && $latencyMilliseconds < 0) {
            throw new InvalidArgumentException('Attempt latency cannot be negative.');
        }
        if ($providerRequests < 1) {
            throw new InvalidArgumentException('Attempt metrics require at least one provider request.');
        }
        if ($promptCharacters < 0 || $responseCharacters < 0) {
            throw new InvalidArgumentException('Attempt character counts cannot be negative.');
        }
        if (! $usagePresent && $usageComplete) {
            throw new InvalidArgumentException('Attempt usage cannot be complete when it is absent.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'provider' => $this->provider,
            'model' => $this->model,
            'resolved_route' => $this->resolvedRoute,
            'model_tier' => $this->modelTier,
            'tokens' => $this->tokens->toArray(),
            'cost_usd' => $this->cost?->toUsdDecimal(),
            'latency_milliseconds' => $this->latencyMilliseconds,
            'provider_requests' => $this->providerRequests,
            'usage_present' => $this->usagePresent,
            'usage_complete' => $this->usageComplete,
            'prompt_characters' => $this->promptCharacters,
            'response_characters' => $this->responseCharacters,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
