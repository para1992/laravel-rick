<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptRegistry;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Llm\ValueObject\StrictSchema;

final readonly class LlmConfiguration
{
    /**
     * @param  array<string, array{provider: string|array<string, string|null>|null, model: string|null}>  $models
     * @param  array<string, array{tier: string, options: array<string, mixed>, escalation_tiers: list<string>}>  $policies
     * @param  array<string, array{version: string, system: string}>  $stepPrompts
     * @param array<string, array{
     *     version: string,
     *     system: string,
     *     instruction: string,
     *     response_contract: string,
     *     output_type: string,
     *     model_policy: string,
     *     validator_sets: list<string>,
     *     output_schema: array<string, mixed>|null
     * }> $operations
     * @param  array<string, array{input_per_million: string, output_per_million: string, cached_input_per_million?: string, cache_write_input_per_million?: string}>  $pricingModels
     * @param  array<string, string>  $pricingTiers
     */
    public function __construct(
        public int $timeout,
        public int $maxPromptCharacters,
        public int $structuredResponseAttempts,
        public string $structuredResponseStrategy,
        public array $models,
        public array $policies,
        public array $stepPrompts,
        public array $operations,
        public array $pricingModels,
        public array $pricingTiers,
        public ?string $pricingSourceUrl,
        public ?string $pricingCheckedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $qualityRuleSets
     */
    public static function from(
        array $input,
        array $qualityRuleSets,
        JsonSchemaValidatorBase $schemas,
    ): self {
        ConfigurationInput::keys(
            $input,
            [
                'timeout', 'max_prompt_characters', 'structured_responses', 'pricing',
                'policies', 'step_prompts', 'operations', 'models',
            ],
            'llm',
        );

        $structured = ConfigurationInput::map(
            $input['structured_responses'] ?? [
                'attempts' => 1,
                'strategy' => 'same_route_then_fallback',
            ],
            'llm.structured_responses',
        );
        ConfigurationInput::keys(
            $structured,
            ['attempts', 'strategy'],
            'llm.structured_responses',
        );
        $structuredAttempts = ConfigurationInput::integer(
            $structured['attempts'] ?? null,
            'llm.structured_responses.attempts',
            1,
            10,
        );
        $structuredStrategy = ConfigurationInput::string(
            $structured['strategy'] ?? null,
            'llm.structured_responses.strategy',
        );
        if ($structuredStrategy !== 'same_route_then_fallback') {
            throw new InvalidArgumentException('Structured response retry strategy is unsupported.');
        }

        $modelsInput = ConfigurationInput::map($input['models'] ?? null, 'llm.models');
        $models = [];
        foreach ($modelsInput as $tier => $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $tier) !== 1) {
                throw new InvalidArgumentException('LLM model tiers must be stable identifiers.');
            }
            $route = ConfigurationInput::map($value, "llm.models.{$tier}");
            ConfigurationInput::keys($route, ['provider', 'model'], "llm.models.{$tier}");
            $provider = $route['provider'] ?? null;
            if (is_array($provider)) {
                if (array_is_list($provider)) {
                    throw new InvalidArgumentException("LLM provider map [{$tier}] must be an object.");
                }
                foreach ($provider as $name => $configured) {
                    if (! is_string($name) || ($configured !== null && ! is_string($configured))) {
                        throw new InvalidArgumentException("LLM provider map [{$tier}] is invalid.");
                    }
                }
            } elseif ($provider !== null && ! is_string($provider)) {
                throw new InvalidArgumentException("LLM provider route [{$tier}] is invalid.");
            }
            $model = $route['model'] ?? null;
            if ($model !== null && ! is_string($model)) {
                throw new InvalidArgumentException("LLM model route [{$tier}] is invalid.");
            }
            $models[$tier] = ['provider' => $provider, 'model' => $model];
        }

        $policiesInput = ConfigurationInput::map($input['policies'] ?? null, 'llm.policies');
        $policies = [];
        foreach ($policiesInput as $id => $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
                throw new InvalidArgumentException('LLM policy IDs must be stable identifiers.');
            }
            $policy = ConfigurationInput::map($value, "llm.policies.{$id}");
            ConfigurationInput::keys($policy, ['tier', 'options', 'escalation_tiers'], "llm.policies.{$id}");
            $tier = ConfigurationInput::string($policy['tier'] ?? null, "llm.policies.{$id}.tier");
            $escalation = ConfigurationInput::stringList(
                $policy['escalation_tiers'] ?? [],
                "llm.policies.{$id}.escalation_tiers",
            );
            foreach ([$tier, ...$escalation] as $route) {
                if (! array_key_exists($route, $models)) {
                    throw new InvalidArgumentException(
                        "LLM policy [{$id}] references unknown model tier [{$route}].",
                    );
                }
            }
            $policies[$id] = [
                'tier' => $tier,
                'options' => ConfigurationInput::map(
                    $policy['options'] ?? null,
                    "llm.policies.{$id}.options",
                ),
                'escalation_tiers' => $escalation,
            ];
        }

        $stepPromptsInput = ConfigurationInput::map(
            $input['step_prompts'] ?? null,
            'llm.step_prompts',
        );
        ConfigurationInput::keys(
            $stepPromptsInput,
            StepPromptRegistry::PROFILE_IDS,
            'llm.step_prompts',
        );
        $missingStepPrompts = array_values(array_diff(
            StepPromptRegistry::PROFILE_IDS,
            array_keys($stepPromptsInput),
        ));
        if ($missingStepPrompts !== []) {
            throw new InvalidArgumentException(
                "Required Rick step prompt [{$missingStepPrompts[0]}] is not configured.",
            );
        }
        $stepPrompts = [];
        foreach (StepPromptRegistry::PROFILE_IDS as $id) {
            $value = $stepPromptsInput[$id];
            $path = "llm.step_prompts.{$id}";
            $profile = ConfigurationInput::map($value, $path);
            ConfigurationInput::keys($profile, ['version', 'system'], $path);
            $stepPrompts[$id] = [
                'version' => ConfigurationInput::string($profile['version'] ?? null, "{$path}.version"),
                'system' => ConfigurationInput::string($profile['system'] ?? null, "{$path}.system"),
            ];
        }

        $operationsInput = ConfigurationInput::map($input['operations'] ?? null, 'llm.operations');
        $operations = [];
        foreach ($operationsInput as $id => $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
                throw new InvalidArgumentException('LLM operation IDs must be stable identifiers.');
            }
            $path = "llm.operations.{$id}";
            $operation = ConfigurationInput::map($value, $path);
            ConfigurationInput::keys($operation, [
                'version', 'system', 'instruction', 'response_contract', 'output_type',
                'model_policy', 'validator_sets', 'output_schema',
            ], $path);
            $contract = ConfigurationInput::string(
                $operation['response_contract'] ?? null,
                "{$path}.response_contract",
            );
            if (ResponseContract::tryFrom($contract) === null) {
                throw new InvalidArgumentException("LLM operation [{$id}] has an invalid response contract.");
            }
            $policy = ConfigurationInput::string($operation['model_policy'] ?? null, "{$path}.model_policy");
            if (! array_key_exists($policy, $policies)) {
                throw new InvalidArgumentException(
                    "LLM operation [{$id}] references unknown model policy [{$policy}].",
                );
            }
            $validators = ConfigurationInput::stringList(
                $operation['validator_sets'] ?? [],
                "{$path}.validator_sets",
            );
            foreach ($validators as $ruleSet) {
                if (! in_array($ruleSet, $qualityRuleSets, true)) {
                    throw new InvalidArgumentException(
                        "LLM operation [{$id}] references unknown quality rule set [{$ruleSet}].",
                    );
                }
            }
            $schema = $operation['output_schema'] ?? null;
            if ($schema !== null) {
                $schema = ConfigurationInput::map($schema, "{$path}.output_schema");
                $schemas->assertSchema($schema);
                if ($contract !== ResponseContract::Text->value) {
                    try {
                        StrictSchema::assertStrict($schema);
                    } catch (InvalidArgumentException $error) {
                        throw new InvalidArgumentException(
                            "LLM operation [{$id}] has an invalid strict output schema: {$error->getMessage()}",
                            previous: $error,
                        );
                    }
                }
            }
            if ($contract === ResponseContract::Json->value && $schema === null) {
                throw new InvalidArgumentException("JSON LLM operation [{$id}] requires an output schema.");
            }
            $operations[$id] = [
                'version' => ConfigurationInput::string($operation['version'] ?? null, "{$path}.version"),
                'system' => ConfigurationInput::string($operation['system'] ?? null, "{$path}.system"),
                'instruction' => ConfigurationInput::string($operation['instruction'] ?? null, "{$path}.instruction"),
                'response_contract' => $contract,
                'output_type' => ConfigurationInput::string($operation['output_type'] ?? null, "{$path}.output_type"),
                'model_policy' => $policy,
                'validator_sets' => $validators,
                'output_schema' => $schema,
            ];
        }

        $pricing = ConfigurationInput::map($input['pricing'] ?? null, 'llm.pricing');
        ConfigurationInput::keys(
            $pricing,
            ['models', 'tiers', 'source_url', 'checked_at'],
            'llm.pricing',
        );
        $pricingModels = self::pricingModels(
            ConfigurationInput::map($pricing['models'] ?? null, 'llm.pricing.models'),
        );
        $pricingTiers = [];
        foreach (ConfigurationInput::map($pricing['tiers'] ?? null, 'llm.pricing.tiers') as $tier => $model) {
            $model = ConfigurationInput::string($model, "llm.pricing.tiers.{$tier}");
            if (! array_key_exists($model, $pricingModels)) {
                throw new InvalidArgumentException(
                    "Pricing tier [{$tier}] references unknown price [{$model}].",
                );
            }
            $pricingTiers[$tier] = $model;
        }
        $sourceUrl = ConfigurationInput::nullableString($pricing['source_url'] ?? null, 'llm.pricing.source_url');
        if ($sourceUrl !== null && filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Pricing source URL is invalid.');
        }
        $checkedAt = ConfigurationInput::nullableString($pricing['checked_at'] ?? null, 'llm.pricing.checked_at');
        if ($checkedAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkedAt) !== 1) {
            throw new InvalidArgumentException('Pricing checked date must use YYYY-MM-DD.');
        }

        return new self(
            ConfigurationInput::integer($input['timeout'] ?? null, 'llm.timeout', 1),
            ConfigurationInput::integer($input['max_prompt_characters'] ?? null, 'llm.max_prompt_characters', 1),
            $structuredAttempts,
            $structuredStrategy,
            $models,
            $policies,
            $stepPrompts,
            $operations,
            $pricingModels,
            $pricingTiers,
            $sourceUrl,
            $checkedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array{input_per_million: string, output_per_million: string, cached_input_per_million?: string, cache_write_input_per_million?: string}>
     */
    private static function pricingModels(array $input): array
    {
        $models = [];
        foreach ($input as $id => $value) {
            if (! str_contains($id, ':')) {
                throw new InvalidArgumentException('Pricing model keys must use provider:model.');
            }
            $path = "llm.pricing.models.{$id}";
            $price = ConfigurationInput::map($value, $path);
            ConfigurationInput::keys($price, [
                'input_per_million', 'output_per_million', 'cached_input_per_million',
                'cache_write_input_per_million',
            ], $path);
            $normalized = [
                'input_per_million' => ConfigurationInput::decimal(
                    $price['input_per_million'] ?? null,
                    "{$path}.input_per_million",
                ),
                'output_per_million' => ConfigurationInput::decimal(
                    $price['output_per_million'] ?? null,
                    "{$path}.output_per_million",
                ),
            ];
            foreach (['cached_input_per_million', 'cache_write_input_per_million'] as $key) {
                if (array_key_exists($key, $price)) {
                    $normalized[$key] = ConfigurationInput::decimal($price[$key], "{$path}.{$key}");
                }
            }
            $models[$id] = $normalized;
        }

        return $models;
    }
}
