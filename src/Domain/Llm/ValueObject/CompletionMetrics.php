<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

use InvalidArgumentException;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;

final readonly class CompletionMetrics
{
    /** @param array<string, mixed> $providerDetails */
    public function __construct(
        public TokenUsage $tokens,
        public ?InvocationCost $cost = null,
        public ?int $latencyMilliseconds = null,
        public array $providerDetails = [],
        public int $providerRequests = 1,
        public bool $usageComplete = true,
        public bool $usagePresent = true,
    ) {
        if ($latencyMilliseconds !== null && $latencyMilliseconds < 0) {
            throw new InvalidArgumentException('Completion latency cannot be negative.');
        }

        if ($providerRequests < 1) {
            throw new InvalidArgumentException('Completion metrics require at least one provider request.');
        }
        if (! $usagePresent && $usageComplete) {
            throw new InvalidArgumentException('Completion usage cannot be complete when it is absent.');
        }
    }
}
