<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;

final readonly class ConfiguredPricing implements PricingBase
{
    /**
     * @param  array<string, array{input_per_million: int|float|string, output_per_million: int|float|string, cached_input_per_million?: int|float|string, cache_write_input_per_million?: int|float|string}>  $models
     * @param  array<string, string>  $tiers
     */
    public function __construct(private array $models, private array $tiers) {}

    public function actual(string $provider, string $model, TokenUsage $usage): ?InvocationCost
    {
        return $this->calculate($this->models[$provider.':'.$model] ?? null, $usage);
    }

    public function estimate(string $modelTier, int $inputTokens, int $outputTokens): ?InvocationCost
    {
        $key = $this->tiers[$modelTier] ?? null;

        return $key === null
            ? null
            : $this->calculate(
                $this->models[$key] ?? null,
                new TokenUsage($inputTokens, $outputTokens),
            );
    }

    /** @param array<string, int|float|string>|null $price */
    private function calculate(?array $price, TokenUsage $usage): ?InvocationCost
    {
        if ($price === null) {
            return null;
        }

        $regularInput = max(
            0,
            $usage->inputTokens
                - $usage->cachedInputTokens
                - $usage->cacheWriteInputTokens,
        );

        return InvocationCost::fromUsd($price['input_per_million'])
            ->multiplyTokens($regularInput)
            ->plus(InvocationCost::fromUsd(
                $price['cached_input_per_million'] ?? $price['input_per_million'],
            )->multiplyTokens($usage->cachedInputTokens))
            ->plus(InvocationCost::fromUsd(
                $price['cache_write_input_per_million'] ?? $price['input_per_million'],
            )->multiplyTokens($usage->cacheWriteInputTokens))
            ->plus(InvocationCost::fromUsd($price['output_per_million'])
                ->multiplyTokens($usage->outputTokens));
    }
}
