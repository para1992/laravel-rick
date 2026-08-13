<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Llm\Interface;

use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;

interface PricingBase
{
    public function actual(string $provider, string $model, TokenUsage $usage): ?InvocationCost;

    public function estimate(string $modelTier, int $inputTokens, int $outputTokens): ?InvocationCost;
}
