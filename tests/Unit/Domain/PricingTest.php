<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Infrastructure\Configuration\ConfigurationInput;
use Rick\Laravel\Infrastructure\Llm\ConfiguredPricing;

final class PricingTest extends TestCase
{
    public function test_configured_pricing_uses_integer_nanodollars_for_actual_and_reserved_costs(): void
    {
        $pricing = new ConfiguredPricing(
            [
                'acme:model-a' => [
                    'input_per_million' => '2',
                    'cached_input_per_million' => '0.5',
                    'output_per_million' => '8',
                ],
            ],
            ['quality' => 'acme:model-a'],
        );

        self::assertSame(
            '0.00925',
            $pricing->actual(
                'acme',
                'model-a',
                new TokenUsage(1_000, 1_000, cachedInputTokens: 500),
            )?->toUsdDecimal(),
        );
        self::assertSame(
            '0.018',
            $pricing->estimate('quality', 1_000, 2_000)?->toUsdDecimal(),
        );
        self::assertNull($pricing->estimate('unknown', 1_000, 2_000));
    }

    public function test_configured_pricing_prices_cache_writes_separately_from_regular_input(): void
    {
        $pricing = new ConfiguredPricing(
            [
                'acme:model-a' => [
                    'input_per_million' => '2',
                    'cached_input_per_million' => '0.5',
                    'cache_write_input_per_million' => '1',
                    'output_per_million' => '8',
                ],
            ],
            [],
        );

        self::assertSame(
            '0.0094',
            $pricing->actual(
                'acme',
                'model-a',
                new TokenUsage(
                    1_000,
                    1_000,
                    cachedInputTokens: 200,
                    cacheWriteInputTokens: 300,
                ),
            )?->toUsdDecimal(),
        );
    }

    public function test_package_catalog_contains_no_mutable_default_prices(): void
    {
        $config = ConfigurationInput::map(
            require dirname(__DIR__, 3).'/config/rick.php',
            'rick',
        );
        $llm = ConfigurationInput::map($config['llm'] ?? null, 'rick.llm');
        $catalog = ConfigurationInput::map($llm['pricing'] ?? null, 'rick.llm.pricing');
        $models = ConfigurationInput::map($catalog['models'] ?? null, 'rick.llm.pricing.models');
        $tiers = ConfigurationInput::map($catalog['tiers'] ?? null, 'rick.llm.pricing.tiers');
        $pricing = new ConfiguredPricing([], []);

        self::assertSame([], $models);
        self::assertSame([], $tiers);
        self::assertNull($pricing->actual('openrouter', 'any-model', new TokenUsage(1, 1)));
    }
}
