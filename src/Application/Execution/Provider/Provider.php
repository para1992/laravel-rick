<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Provider;

use Illuminate\Contracts\Foundation\Application;
use Rick\Laravel\Application\Execution\Contract\ExecutionGateContract;
use Rick\Laravel\Application\Execution\Pipe\ContinueRunPipe;
use Rick\Laravel\Application\Execution\Pipe\ExecuteInvocationPipe;
use Rick\Laravel\Application\Execution\Pipe\FailInvocationPipe;
use Rick\Laravel\Application\Execution\Pipe\GetDeliverySnapshotPipe;
use Rick\Laravel\Application\Execution\Pipe\GetPendingInputPipe;
use Rick\Laravel\Application\Execution\Pipe\GetPendingInteractionPipe;
use Rick\Laravel\Application\Execution\Pipe\GetPendingReviewPipe;
use Rick\Laravel\Application\Execution\Pipe\GetRunMetricsPipe;
use Rick\Laravel\Application\Execution\Pipe\GetRunProgressPipe;
use Rick\Laravel\Application\Execution\Pipe\GetRunSnapshotPipe;
use Rick\Laravel\Application\Execution\Pipe\GetRunTimelinePipe;
use Rick\Laravel\Application\Execution\Pipe\ListRunsPipe;
use Rick\Laravel\Application\Execution\Pipe\RecoverRunPipe;
use Rick\Laravel\Application\Execution\Pipe\ResumeRunPipe;
use Rick\Laravel\Application\Execution\Pipe\RunWorkflowPipe;
use Rick\Laravel\Application\Execution\Pipe\ScheduleRunPipe;
use Rick\Laravel\Application\Execution\Pipe\SelectCandidatePipe;
use Rick\Laravel\Application\Execution\Pipe\SubmitInputPipe;
use Rick\Laravel\Application\Execution\Strategy\GroundedVerifyStrategy;
use Rick\Laravel\Application\Execution\Support\Dispatch\InvocationDispatch;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Llm\PromptBounds;
use Rick\Laravel\Application\Execution\Support\Quality\Policy\BoundedRepairPolicy;
use Rick\Laravel\Application\Execution\Support\Quality\Policy\FailRepairPolicy;
use Rick\Laravel\Application\Execution\Support\Quality\RepairPolicyRegistry;
use Rick\Laravel\Application\Execution\Support\Quality\Rules\MinimumCharactersRule;
use Rick\Laravel\Application\Execution\Support\Quality\Rules\RegexRule;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSet;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSetRegistry;
use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Application\Interface\ExecutionConfigurationBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Provider\ProviderBase;

final class Provider extends ProviderBase
{
    protected function scoped(): array
    {
        return [
            DomainEventRecorder::class => DomainEventRecorder::class,
        ];
    }

    protected function gateContract(): string
    {
        return ExecutionGateContract::class;
    }

    /** @return list<class-string<PipeBase>> */
    protected function pipes(): array
    {
        return [
            RunWorkflowPipe::class,
            ScheduleRunPipe::class,
            ContinueRunPipe::class,
            ExecuteInvocationPipe::class,
            FailInvocationPipe::class,
            RecoverRunPipe::class,
            ResumeRunPipe::class,
            GetRunSnapshotPipe::class,
            GetRunMetricsPipe::class,
            GetRunProgressPipe::class,
            ListRunsPipe::class,
            GetRunTimelinePipe::class,
            GetDeliverySnapshotPipe::class,
            GetPendingInteractionPipe::class,
            GetPendingReviewPipe::class,
            GetPendingInputPipe::class,
            SubmitInputPipe::class,
            SelectCandidatePipe::class,
        ];
    }

    public function boot(): void
    {
        $this->app
            ->when(ExecuteInvocationPipe::class)
            ->needs('$leaseSeconds')
            ->give(static fn (Application $app): int => $app
                ->make(ExecutionConfigurationBase::class)
                ->invocationLeaseSeconds());
        $this->app
            ->when(ExecuteInvocationPipe::class)
            ->needs('$maxSafeAttempts')
            ->give(static fn (Application $app): int => $app
                ->make(ExecutionConfigurationBase::class)
                ->maxSafeAttempts());
        $this->app
            ->when(ExecuteInvocationPipe::class)
            ->needs('$structuredResponseAttempts')
            ->give(static fn (Application $app): int => $app
                ->make(ExecutionConfigurationBase::class)
                ->structuredResponseAttempts());
        $this->app
            ->when(ExecuteInvocationPipe::class)
            ->needs('$structuredResponseStrategy')
            ->give(static fn (Application $app): string => $app
                ->make(ExecutionConfigurationBase::class)
                ->structuredResponseStrategy());
        $this->app
            ->when(PromptBounds::class)
            ->needs('$maxCharacters')
            ->give(static fn (Application $app): int => $app
                ->make(ExecutionConfigurationBase::class)
                ->maxPromptCharacters());
        $this->app
            ->when(InvocationDispatch::class)
            ->needs('$maxInFlightInvocations')
            ->give(static fn (Application $app): int => $app
                ->make(ExecutionConfigurationBase::class)
                ->maxInFlightInvocations());
        $this->app
            ->when(GroundedVerifyStrategy::class)
            ->needs('$verificationBatchSize')
            ->give(static fn (Application $app): int => $app
                ->make(ExecutionConfigurationBase::class)
                ->groundedVerificationBatchSize());
        $this->app->singleton(
            RuleSetRegistry::class,
            static function (Application $app): RuleSetRegistry {
                $sets = [];
                foreach ($app->make(ExecutionConfigurationBase::class)->qualityRuleSets() as $id => $definitions) {
                    $rules = [];
                    foreach ($definitions as $definition) {
                        $ruleId = $definition['id'];
                        $rules[] = $definition['type'] === 'minimum_characters'
                            ? new MinimumCharactersRule(
                                $ruleId,
                                $definition['minimum'],
                            )
                            : new RegexRule(
                                $ruleId,
                                $definition['pattern'],
                                $definition['must_match'],
                                $definition['description'],
                            );
                    }
                    $sets[] = new RuleSet($id, $rules);
                }

                return new RuleSetRegistry($sets);
            },
        );
        $this->app->singleton(
            RepairPolicyRegistry::class,
            static fn (): RepairPolicyRegistry => new RepairPolicyRegistry([
                new FailRepairPolicy,
                new BoundedRepairPolicy,
            ]),
        );
        $this->app->singleton(
            StepStrategyRegistry::class,
            static fn (Application $app): StepStrategyRegistry => new StepStrategyRegistry(
                $app,
                $app->make(ExecutionConfigurationBase::class)->strategies(),
            ),
        );
    }
}
