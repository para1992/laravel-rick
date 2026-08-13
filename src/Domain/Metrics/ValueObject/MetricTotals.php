<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use JsonSerializable;

final readonly class MetricTotals implements JsonSerializable
{
    public function __construct(
        public int $calls,
        public int $succeededCalls,
        public int $failedCalls,
        public int $pendingCalls,
        public int $runningCalls,
        public int $indeterminateCalls,
        public int $attempts,
        public int $providerRequests,
        public int $measuredSucceededCalls,
        public int $unmeasuredSucceededCalls,
        public int $incompleteUsageCalls,
        public int $unpricedSucceededCalls,
        public int $promptCharacters,
        public int $responseCharacters,
        public int $latencyMilliseconds,
        public TokenUsage $tokens,
        public InvocationCost $cost,
        public int $measuredAttempts,
        public int $incompleteUsageAttempts,
        public int $unpricedAttempts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 2,
            'calls' => $this->calls,
            'succeeded_calls' => $this->succeededCalls,
            'failed_calls' => $this->failedCalls,
            'pending_calls' => $this->pendingCalls,
            'running_calls' => $this->runningCalls,
            'indeterminate_calls' => $this->indeterminateCalls,
            'attempts' => $this->attempts,
            'provider_requests' => $this->providerRequests,
            'measured_succeeded_calls' => $this->measuredSucceededCalls,
            'unmeasured_succeeded_calls' => $this->unmeasuredSucceededCalls,
            'incomplete_usage_calls' => $this->incompleteUsageCalls,
            'unpriced_succeeded_calls' => $this->unpricedSucceededCalls,
            'prompt_characters' => $this->promptCharacters,
            'response_characters' => $this->responseCharacters,
            'latency_milliseconds' => $this->latencyMilliseconds,
            'tokens' => $this->tokens->toArray(),
            'cost_usd' => $this->cost->toUsdDecimal(),
            'measured_attempts' => $this->measuredAttempts,
            'incomplete_usage_attempts' => $this->incompleteUsageAttempts,
            'unpriced_attempts' => $this->unpricedAttempts,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
