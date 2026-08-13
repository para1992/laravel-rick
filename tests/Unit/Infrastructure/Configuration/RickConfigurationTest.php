<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Infrastructure\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptRegistry;
use Rick\Laravel\Infrastructure\Configuration\ConfigurationInput;
use Rick\Laravel\Infrastructure\Configuration\RickConfiguration;
use Rick\Laravel\Infrastructure\Schema\JsonSchemaValidator;
use Rick\Laravel\Infrastructure\Support\ConfiguredTenantCatalog;
use stdClass;

final class RickConfigurationTest extends TestCase
{
    public function test_default_configuration_is_typed_and_valid(): void
    {
        $configuration = $this->configuration($this->defaults());

        self::assertSame('default', $configuration->tenant->default);
        self::assertSame(100, $configuration->execution->recoveryBatchSize);
        self::assertSame(1, $configuration->llm->structuredResponseAttempts);
        self::assertSame(
            'same_route_then_fallback',
            $configuration->llm->structuredResponseStrategy,
        );
        self::assertSame([], $configuration->llm->pricingModels);
        self::assertSame([], $configuration->llm->pricingTiers);
        self::assertSame(
            StepPromptRegistry::PROFILE_IDS,
            array_keys($configuration->llm->stepPrompts),
        );
    }

    public function test_invalid_structured_response_retry_configuration_fails_fast(): void
    {
        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $structured = ConfigurationInput::map(
            $llm['structured_responses'] ?? null,
            'llm.structured_responses',
        );
        $structured['attempts'] = 0;
        $llm['structured_responses'] = $structured;
        $input['llm'] = $llm;

        try {
            $this->configuration($input);
            self::fail('A zero structured-response attempt limit should fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString(
                'llm.structured_responses.attempts',
                $error->getMessage(),
            );
        }

        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $structured = ConfigurationInput::map(
            $llm['structured_responses'] ?? null,
            'llm.structured_responses',
        );
        $structured['strategy'] = 'queue_attempts';
        $llm['structured_responses'] = $structured;
        $input['llm'] = $llm;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry strategy is unsupported');
        $this->configuration($input);
    }

    public function test_legacy_published_configuration_keeps_the_non_retrying_default(): void
    {
        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        unset($llm['structured_responses']);
        $input['llm'] = $llm;

        $configuration = $this->configuration($input);

        self::assertSame(1, $configuration->llm->structuredResponseAttempts);
        self::assertSame(
            'same_route_then_fallback',
            $configuration->llm->structuredResponseStrategy,
        );
    }

    public function test_unknown_package_key_fails_fast(): void
    {
        $input = $this->defaults();
        $input['unknown'] = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Rick configuration key');
        $this->configuration($input);
    }

    public function test_tenant_catalog_defaults_and_deduplicates_explicit_ids(): void
    {
        $configuration = $this->configuration($this->defaults());
        self::assertSame(
            ['default'],
            [...(new ConfiguredTenantCatalog($configuration))->tenantIds()],
        );

        $input = $this->defaults();
        $tenant = ConfigurationInput::map($input['tenant'] ?? null, 'tenant');
        $tenant['catalog'] = ['tenant-a', 'tenant-b', 'tenant-a'];
        $input['tenant'] = $tenant;
        $configured = $this->configuration($input);

        self::assertSame(['tenant-a', 'tenant-b'], $configured->tenant->catalog);
        self::assertSame(
            ['tenant-a', 'tenant-b'],
            [...(new ConfiguredTenantCatalog($configured))->tenantIds()],
        );
    }

    public function test_invalid_range_and_strategy_class_fail_fast(): void
    {
        $input = $this->defaults();
        $execution = ConfigurationInput::map($input['execution'] ?? null, 'execution');
        $execution['recovery_batch_size'] = 0;
        $input['execution'] = $execution;

        try {
            $this->configuration($input);
            self::fail('An invalid recovery batch should fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('recovery_batch_size', $error->getMessage());
        }

        $input = $this->defaults();
        $execution = ConfigurationInput::map($input['execution'] ?? null, 'execution');
        $strategies = ConfigurationInput::map($execution['strategies'] ?? null, 'execution.strategies');
        $strategies['resolve'] = stdClass::class;
        $execution['strategies'] = $strategies;
        $input['execution'] = $execution;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement StepStrategyBase');
        $this->configuration($input);
    }

    public function test_broken_pricing_and_operation_cross_references_fail_fast(): void
    {
        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $pricing = ConfigurationInput::map($llm['pricing'] ?? null, 'llm.pricing');
        $pricing['tiers'] = ['quality' => 'missing:model'];
        $llm['pricing'] = $pricing;
        $input['llm'] = $llm;

        try {
            $this->configuration($input);
            self::fail('A missing price target should fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('references unknown price', $error->getMessage());
        }

        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $operations = ConfigurationInput::map($llm['operations'] ?? null, 'llm.operations');
        $operation = ConfigurationInput::map($operations['rick.text'] ?? null, 'llm.operations.rick.text');
        $operation['model_policy'] = 'missing_policy';
        $operations['rick.text'] = $operation;
        $llm['operations'] = $operations;
        $input['llm'] = $llm;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('references unknown model policy');
        $this->configuration($input);
    }

    public function test_missing_step_prompt_profile_fails_fast(): void
    {
        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $profiles = ConfigurationInput::map(
            $llm['step_prompts'] ?? null,
            'llm.step_prompts',
        );
        unset($profiles['rick.step.generate']);
        $llm['step_prompts'] = $profiles;
        $input['llm'] = $llm;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Required Rick step prompt [rick.step.generate] is not configured.',
        );
        $this->configuration($input);
    }

    public function test_invalid_decimal_regex_and_json_schema_fail_fast(): void
    {
        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $pricing = ConfigurationInput::map($llm['pricing'] ?? null, 'llm.pricing');
        $pricing['models'] = [
            'provider:model' => [
                'input_per_million' => 1.25,
                'output_per_million' => '5',
            ],
        ];
        $llm['pricing'] = $pricing;
        $input['llm'] = $llm;

        try {
            $this->configuration($input);
            self::fail('A non-string price should fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('decimal string', $error->getMessage());
        }

        $input = $this->defaults();
        $quality = ConfigurationInput::map($input['quality'] ?? null, 'quality');
        $quality['rule_sets'] = [
            'broken' => [[
                'id' => 'broken.regex',
                'type' => 'regex',
                'pattern' => '/[/',
                'must_match' => true,
                'description' => 'Broken regex',
            ]],
        ];
        $input['quality'] = $quality;

        try {
            $this->configuration($input);
            self::fail('A malformed quality regex should fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('regex', $error->getMessage());
        }

        $input = $this->defaults();
        $llm = ConfigurationInput::map($input['llm'] ?? null, 'llm');
        $operations = ConfigurationInput::map($llm['operations'] ?? null, 'llm.operations');
        $operation = ConfigurationInput::map(
            $operations['rick.verify.grounded'] ?? null,
            'llm.operations.rick.verify.grounded',
        );
        $operation['output_schema'] = ['type' => ['object', 42]];
        $operations['rick.verify.grounded'] = $operation;
        $llm['operations'] = $operations;
        $input['llm'] = $llm;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Schema is invalid');
        $this->configuration($input);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ConfigurationInput::map(
            require dirname(__DIR__, 4).'/config/rick.php',
            'rick',
        );
    }

    /** @param array<string, mixed> $input */
    private function configuration(array $input): RickConfiguration
    {
        return RickConfiguration::from($input, new JsonSchemaValidator);
    }
}
