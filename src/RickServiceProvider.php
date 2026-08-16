<?php

declare(strict_types=1);

namespace Rick\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Rick\Laravel\Application\Compilation\Provider\Provider as CompilationProvider;
use Rick\Laravel\Application\Execution\Provider\Provider as ExecutionProvider;
use Rick\Laravel\Application\Interface\ExecutionConfigurationBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Application\Orchestration\Pipe\DispatchPipe;
use Rick\Laravel\Infrastructure\Configuration\ConfigurationInput;
use Rick\Laravel\Infrastructure\Configuration\RickConfiguration;
use Rick\Laravel\Infrastructure\Console\DiagnoseCommand;
use Rick\Laravel\Infrastructure\Console\InspectRunCommand;
use Rick\Laravel\Infrastructure\Console\ListRecipesCommand;
use Rick\Laravel\Infrastructure\Console\MakeRickWorkflowCommand;
use Rick\Laravel\Infrastructure\Console\MigrateLegacyCommand;
use Rick\Laravel\Infrastructure\Console\OutboxRelayCommand;
use Rick\Laravel\Infrastructure\Console\PruneCommand;
use Rick\Laravel\Infrastructure\Console\RecoverInvocationsCommand;
use Rick\Laravel\Infrastructure\Console\RecoverRunCommand;
use Rick\Laravel\Infrastructure\Console\ResolveInvocationCommand;
use Rick\Laravel\Infrastructure\Console\RetryOutboxCommand;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;
use Rick\Laravel\Infrastructure\Provider\Provider as InfrastructureProvider;
use Rick\Laravel\Infrastructure\Recovery\InvocationRecoveryRunner;
use Rick\Laravel\Provider\ProviderBase;

final class RickServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rick.php', 'rick');
        $this->app->register(InfrastructureProvider::class);
        $this->app->register(CompilationProvider::class);
        $this->app->register(ExecutionProvider::class);

        $this->app->singleton(
            RickConfiguration::class,
            static function (Application $app): RickConfiguration {
                $configured = $app->make(ConfigRepository::class)->get('rick');
                if (! is_array($configured)) {
                    throw new InvalidArgumentException('Rick configuration must be an object.');
                }

                return RickConfiguration::from(
                    ConfigurationInput::map($configured, 'rick'),
                    $app->make(JsonSchemaValidatorBase::class),
                );
            },
        );
        $this->app->alias(RickConfiguration::class, ExecutionConfigurationBase::class);
        $this->app
            ->when(InvocationRecoveryRunner::class)
            ->needs('$batchSize')
            ->give(static fn (Application $app): int => $app
                ->make(RickConfiguration::class)
                ->execution
                ->recoveryBatchSize);

        $this->app
            ->when(DispatchPipe::class)
            ->needs('$modules')
            ->giveTagged(ProviderBase::ORCHESTRATION_MODULE_TAG);
        $this->app->scoped(DispatchPipe::class);

        $this->app->scoped(
            Handler::class,
            static fn (Application $app): Handler => new Handler(
                new Pipeline($app),
                [$app->make(DispatchPipe::class)],
            ),
        );

        $this->app->scoped(
            Rick::class,
            static fn (Application $app): Rick => new Rick(
                $app->make(Handler::class),
                $app->make(IdGeneratorBase::class),
                $app->make(OutboxRelay::class),
            ),
        );
    }

    public function boot(): void
    {
        $configuration = $this->app->make(RickConfiguration::class);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/rick.php' => config_path('rick.php'),
        ], 'rick-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DiagnoseCommand::class,
                InspectRunCommand::class,
                ListRecipesCommand::class,
                MakeRickWorkflowCommand::class,
                MigrateLegacyCommand::class,
                OutboxRelayCommand::class,
                PruneCommand::class,
                RecoverInvocationsCommand::class,
                RecoverRunCommand::class,
                ResolveInvocationCommand::class,
                RetryOutboxCommand::class,
            ]);
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($configuration): void {
                if ($configuration->outbox->scheduleEnabled) {
                    $schedule->command('rick:outbox:relay --all')->everyMinute()->withoutOverlapping();
                }
                if ($configuration->execution->recoveryScheduleEnabled) {
                    $schedule->command('rick:recover --all')->everyMinute()->withoutOverlapping();
                }
                if ($configuration->retention->scheduleEnabled) {
                    $schedule->command('rick:prune --all')->daily()->withoutOverlapping();
                }
            });
        }
    }
}
