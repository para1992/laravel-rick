<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Tests\TestCase;

final class PricingConfigurationTest extends TestCase
{
    public function test_package_pricing_catalog_is_empty_by_default(): void
    {
        self::assertNull(
            $this->application()->make(PricingBase::class)
                ->estimate('cheap', 1_087, 533),
        );
    }

    public function test_unknown_actual_provider_price_is_not_invented(): void
    {
        self::assertNull(
            $this->application()->make(PricingBase::class)
                ->actual('openrouter', 'unconfigured-model', new TokenUsage(1, 1)),
        );
    }
}
