<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Provider;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;
use Rick\Laravel\Application\Compilation\Support\Recipe\HumanizerRecipe;
use Rick\Laravel\Application\Compilation\Support\Recipe\LongFormRecipe;
use Rick\Laravel\Application\Compilation\Support\Recipe\MultiPerspectiveAnalysisRecipe;
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;
use Rick\Laravel\Application\Compilation\Support\Recipe\RefactoringPlanRecipe;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionReadModelBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\PricingBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\ProviderRouteResolverBase;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicy;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicyRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\PromptTemplate;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\TemplateLlmOperation;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptDefinition;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\StepPromptRegistry;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Execution\Support\Schema\StructuredResponseDecoder;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Infrastructure\Configuration\RickConfiguration;
use Rick\Laravel\Infrastructure\Llm\ConfiguredPricing;
use Rick\Laravel\Infrastructure\Llm\LaravelAiGateway;
use Rick\Laravel\Infrastructure\Llm\ModelRouter;
use Rick\Laravel\Infrastructure\Llm\PromptMapper;
use Rick\Laravel\Infrastructure\Migration\LegacyDataMigration;
use Rick\Laravel\Infrastructure\Migration\LegacyPayloadConverter;
use Rick\Laravel\Infrastructure\Outbox\DatabaseEventOutbox;
use Rick\Laravel\Infrastructure\Outbox\DatabaseOutboxExecutionBackend;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;
use Rick\Laravel\Infrastructure\Outbox\OutboxWriter;
use Rick\Laravel\Infrastructure\Persistence\DatabaseExecutionReadModel;
use Rick\Laravel\Infrastructure\Persistence\DatabaseExecutionRepository;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRunRepository;
use Rick\Laravel\Infrastructure\Persistence\Json\AttemptMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionRequestCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\CompletionResponseCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\InteractionStateCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonRunStateCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\RunMetricsCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\StructuredResponseDiagnosticCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkflowStepCodec;
use Rick\Laravel\Infrastructure\Persistence\Json\WorkingMemoryCodec;
use Rick\Laravel\Infrastructure\Persistence\LaravelPayloadProtector;
use Rick\Laravel\Infrastructure\Persistence\LaravelTransaction;
use Rick\Laravel\Infrastructure\Persistence\RunPruner;
use Rick\Laravel\Infrastructure\Persistence\SqliteQueueProfile;
use Rick\Laravel\Infrastructure\Schema\JsonSchemaValidator;
use Rick\Laravel\Infrastructure\Support\ConfiguredTenantCatalog;
use Rick\Laravel\Infrastructure\Support\LaravelClock;
use Rick\Laravel\Infrastructure\Support\LaravelIdGenerator;
use Rick\Laravel\Infrastructure\Support\LaravelTenantContext;

final class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ModelPolicyRegistry::class,
            static function (Application $app): ModelPolicyRegistry {
                $configuration = $app->make(RickConfiguration::class);
                $policies = [];
                foreach ($configuration->llm->policies as $id => $policy) {
                    $policies[] = new ModelPolicy(
                        $id,
                        $policy['tier'],
                        $policy['options'],
                        $policy['escalation_tiers'],
                    );
                }

                return new ModelPolicyRegistry($policies);
            },
        );
        $this->app->singleton(
            RecipeRegistry::class,
            static fn (): RecipeRegistry => new RecipeRegistry([
                new HumanizerRecipe,
                new LongFormRecipe,
                new MultiPerspectiveAnalysisRecipe,
                new RefactoringPlanRecipe,
            ]),
        );
        $this->app->singleton(
            StepPromptRegistry::class,
            static function (Application $app): StepPromptRegistry {
                $configuration = $app->make(RickConfiguration::class);
                $prompts = [];
                foreach ($configuration->llm->stepPrompts as $id => $prompt) {
                    $prompts[] = new StepPromptDefinition(
                        $id,
                        $prompt['version'],
                        $prompt['system'],
                    );
                }

                return new StepPromptRegistry($prompts);
            },
        );
        $this->app->singleton(
            LlmOperationRegistry::class,
            static function (Application $app): LlmOperationRegistry {
                $configuration = $app->make(RickConfiguration::class);
                $policies = $app->make(ModelPolicyRegistry::class);
                $operations = [];
                foreach ($configuration->llm->operations as $id => $operation) {
                    $operations[] = new TemplateLlmOperation(new LlmOperationDefinition(
                        $id,
                        $operation['version'],
                        new PromptTemplate(
                            $operation['system'],
                            $operation['instruction'],
                            $operation['output_schema'],
                        ),
                        ResponseContract::from($operation['response_contract']),
                        ArtifactType::fromString($operation['output_type']),
                        $policies->get($operation['model_policy']),
                        $operation['validator_sets'],
                    ));
                }

                return new LlmOperationRegistry($operations);
            },
        );
        $this->app->singleton(
            ModelRouter::class,
            static fn (Application $app): ModelRouter => new ModelRouter(
                $app->make(RickConfiguration::class)->llm->models,
            ),
        );
        $this->app->alias(ModelRouter::class, ProviderRouteResolverBase::class);
        $this->app->singleton(PromptMapper::class);
        $this->app->singleton(
            PricingBase::class,
            static function (Application $app): ConfiguredPricing {
                $configuration = $app->make(RickConfiguration::class);
                $models = $configuration->llm->pricingModels;
                $tiers = $configuration->llm->pricingTiers;
                $routes = $configuration->llm->models;
                $policies = $configuration->llm->policies;
                $candidates = array_unique([
                    ...array_keys($routes),
                    ...array_keys($policies),
                    'default',
                ]);
                $router = $app->make(ModelRouter::class);

                foreach ($candidates as $tier) {
                    if (isset($tiers[$tier])) {
                        continue;
                    }
                    $route = $router->route($tier);
                    $provider = $route['provider'] ?? null;
                    $model = $route['model'] ?? null;
                    if (! is_string($provider) || ! is_string($model)) {
                        continue;
                    }
                    $key = $provider.':'.$model;
                    if (isset($models[$key])) {
                        $tiers[$tier] = $key;
                    }
                }

                return new ConfiguredPricing($models, $tiers);
            },
        );
        $this->app->singleton(
            GatewayBase::class,
            static fn (Application $app): LaravelAiGateway => new LaravelAiGateway(
                $app->make(ModelRouter::class),
                $app->make(PromptMapper::class),
                $app->make(PricingBase::class),
                $app->make(RickConfiguration::class)->llm->timeout,
                responseSchemas: $app->make(ResponseSchemaResolver::class),
                structuredResponses: $app->make(StructuredResponseDecoder::class),
            ),
        );
        $this->app->singleton(
            WorkflowStepCodec::class,
            static fn (Application $app): WorkflowStepCodec => new WorkflowStepCodec(
                self::stepCodecs($app),
            ),
        );
        $this->app->singleton(JsonRunStateCodec::class);
        $this->app->singleton(WorkingMemoryCodec::class);
        $this->app->singleton(RunMetricsCodec::class);
        $this->app->singleton(InteractionStateCodec::class);
        $this->app->singleton(CompletionRequestCodec::class);
        $this->app->singleton(CompletionResponseCodec::class);
        $this->app->singleton(CompletionMetricsCodec::class);
        $this->app->singleton(AttemptMetricsCodec::class);
        $this->app->singleton(StructuredResponseDiagnosticCodec::class);
        $this->app->singleton(DomainEventCodec::class);
        $this->app->singleton(PayloadProtectorBase::class, LaravelPayloadProtector::class);
        $this->app->singleton(
            LegacyPayloadConverter::class,
            static fn (Application $app): LegacyPayloadConverter => new LegacyPayloadConverter(
                self::stepCodecs($app),
            ),
        );
        $this->app->singleton(
            LegacyDataMigration::class,
            static function (Application $app): LegacyDataMigration {
                $configuration = $app->make(RickConfiguration::class);

                return new LegacyDataMigration(
                    $app->make(ConnectionInterface::class),
                    $app->make(Encrypter::class),
                    $app->make(PayloadProtectorBase::class),
                    $app->make(LegacyPayloadConverter::class),
                    $configuration->persistence->legacySource,
                    [
                        'runs' => $configuration->persistence->tables['runs'],
                        'step_executions' => $configuration->persistence->tables['step_executions'],
                        'llm_invocations' => $configuration->persistence->tables['llm_invocations'],
                        'invocation_attempts' => $configuration->persistence->tables['invocation_attempts'],
                    ],
                );
            },
        );
        $this->app->scoped(
            TenantContextBase::class,
            static fn (Application $app): LaravelTenantContext => new LaravelTenantContext(
                $app->make(RickConfiguration::class)->tenant->default,
            ),
        );
        $this->app->singleton(TenantCatalogBase::class, ConfiguredTenantCatalog::class);
        $this->app->singleton(ClockBase::class, LaravelClock::class);
        $this->app->singleton(IdGeneratorBase::class, LaravelIdGenerator::class);
        $this->app->singleton(JsonSchemaValidatorBase::class, JsonSchemaValidator::class);
        $this->app->scoped(
            TransactionBase::class,
            fn (Application $app): LaravelTransaction => new LaravelTransaction(
                self::connection($app),
            ),
        );
        $this->app->scoped(
            SqliteQueueProfile::class,
            fn (Application $app): SqliteQueueProfile => new SqliteQueueProfile(
                self::connection($app),
            ),
        );
        $this->app->scoped(
            RunRepositoryBase::class,
            static fn (Application $app): DatabaseRunRepository => new DatabaseRunRepository(
                $app->make(ConnectionInterface::class),
                $app->make(JsonRunStateCodec::class),
                $app->make(PayloadProtectorBase::class),
                $app->make(TenantContextBase::class),
                $app->make(RickConfiguration::class)->persistence->tables['runs'],
            ),
        );
        $this->app->scoped(
            ExecutionRepositoryBase::class,
            static fn (Application $app): DatabaseExecutionRepository => new DatabaseExecutionRepository(
                $app->make(ConnectionInterface::class),
                $app->make(CompletionRequestCodec::class),
                $app->make(CompletionResponseCodec::class),
                $app->make(CompletionMetricsCodec::class),
                $app->make(AttemptMetricsCodec::class),
                $app->make(StructuredResponseDiagnosticCodec::class),
                $app->make(PayloadProtectorBase::class),
                $app->make(TenantContextBase::class),
                $app->make(RickConfiguration::class)->persistence->tables['step_executions'],
                $app->make(RickConfiguration::class)->persistence->tables['llm_invocations'],
                $app->make(RickConfiguration::class)->persistence->tables['invocation_attempts'],
            ),
        );
        $this->app->scoped(
            ExecutionReadModelBase::class,
            static fn (Application $app): DatabaseExecutionReadModel => new DatabaseExecutionReadModel(
                $app->make(ConnectionInterface::class),
                $app->make(CompletionRequestCodec::class),
                $app->make(DomainEventCodec::class),
                $app->make(AttemptMetricsCodec::class),
                $app->make(StructuredResponseDiagnosticCodec::class),
                $app->make(PayloadProtectorBase::class),
                $app->make(TenantContextBase::class),
                $app->make(RickConfiguration::class)->persistence->tables['runs'],
                $app->make(RickConfiguration::class)->persistence->tables['llm_invocations'],
                $app->make(RickConfiguration::class)->persistence->tables['invocation_attempts'],
                $app->make(RickConfiguration::class)->persistence->tables['outbox'],
                $app->make(RickConfiguration::class)->persistence->tables['observations'],
            ),
        );
        $this->app->scoped(
            RunPruner::class,
            static fn (Application $app): RunPruner => new RunPruner(
                self::connection($app),
                $app->make(TransactionBase::class),
                $app->make(TenantContextBase::class),
                $app->make(RickConfiguration::class)->persistence->tables['runs'],
                $app->make(RickConfiguration::class)->persistence->tables['outbox'],
            ),
        );
        $this->app->scoped(
            OutboxRelay::class,
            static function (Application $app): OutboxRelay {
                $configuration = $app->make(RickConfiguration::class);

                return new OutboxRelay(
                    self::connection($app),
                    $app->make(Dispatcher::class),
                    $app->make(EventDispatcher::class),
                    $app->make(DomainEventCodec::class),
                    $app->make(PayloadProtectorBase::class),
                    $app->make(TenantContextBase::class),
                    $app->make(ClockBase::class),
                    $app->make(IdGeneratorBase::class),
                    $configuration->persistence->tables['outbox'],
                    $configuration->outbox->batchSize,
                    $configuration->outbox->leaseSeconds,
                    $configuration->outbox->maxAttempts,
                    $configuration->outbox->retryBaseSeconds,
                    $configuration->outbox->retryMaxSeconds,
                    $configuration->queue->connection,
                    $configuration->queue->control,
                    $configuration->queue->llm,
                    $configuration->queue->continue->tries,
                    $configuration->queue->continue->timeout,
                    $configuration->queue->continue->backoff,
                    $configuration->queue->invocation->tries,
                    $configuration->queue->invocation->timeout,
                    $configuration->queue->invocation->backoff,
                );
            },
        );
        $this->app->scoped(
            OutboxWriter::class,
            static fn (Application $app): OutboxWriter => new OutboxWriter(
                self::connection($app),
                $app->make(TenantContextBase::class),
                $app->make(ClockBase::class),
                $app->make(IdGeneratorBase::class),
                $app->make(TransactionBase::class),
                $app->make(OutboxRelay::class),
                $app->make(RickConfiguration::class)->persistence->tables['outbox'],
            ),
        );
        $this->app->scoped(EventOutboxBase::class, DatabaseEventOutbox::class);
        $this->app->scoped(
            ExecutionBackendBase::class,
            fn (Application $app): DatabaseOutboxExecutionBackend => new DatabaseOutboxExecutionBackend(
                $app->make(OutboxWriter::class),
            ),
        );
    }

    /** @return list<StepCodecBase> */
    private static function stepCodecs(Application $app): array
    {
        $codecs = [];
        foreach ($app->make(RickConfiguration::class)->persistence->stepCodecs as $type => $class) {
            $codec = $app->make($class);
            if (! $codec instanceof StepCodecBase) {
                throw new InvalidArgumentException("Custom step codec [{$class}] is invalid.");
            }
            if ($codec->type()->toString() !== $type) {
                throw new InvalidArgumentException(
                    "Custom step codec [{$class}] does not match configured type [{$type}].",
                );
            }
            $codecs[] = $codec;
        }

        return $codecs;
    }

    private static function connection(Application $app): Connection
    {
        return $app->make(ConnectionInterface::class);
    }
}
