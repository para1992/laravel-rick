<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Rick\Laravel\Application\Execution\Contract\ExecutionGateContract;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Pipe\ContinueRunPipe;
use Rick\Laravel\Application\Execution\Strategy\ResolveStrategy;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Registry\StepStrategyRegistry;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Application\Orchestration\Pipe\DispatchPipe;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Facade\Rick as RickFacade;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonRunStateCodec;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Infrastructure\Queue\Job\ExecuteInvocationJob;
use Rick\Laravel\Infrastructure\Queue\QueueLock;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class ScopedLifecycleTest extends TestCase
{
    public function test_tenant_sensitive_graph_is_recreated_between_laravel_scopes(): void
    {
        $application = $this->application();
        $contracts = [
            TenantContextBase::class,
            TransactionBase::class,
            RunRepositoryBase::class,
            ExecutionRepositoryBase::class,
            ExecutionBackendBase::class,
            EventOutboxBase::class,
            OutboxRelay::class,
            DispatchPipe::class,
            Handler::class,
            Rick::class,
            DomainEventRecorder::class,
        ];
        $before = [];
        foreach ($contracts as $contract) {
            $before[$contract] = $application->make($contract);
        }

        $application->forgetScopedInstances();

        foreach ($contracts as $contract) {
            self::assertNotSame($before[$contract], $application->make($contract), $contract);
        }
        self::assertSame('default', $application->make(TenantContextBase::class)->id());
    }

    public function test_stateless_components_are_singletons_while_gates_pipes_and_strategies_are_transient(): void
    {
        $application = $this->application();

        foreach ([
            JsonRunStateCodec::class,
            ClockBase::class,
            IdGeneratorBase::class,
            PayloadProtectorBase::class,
            StepStrategyRegistry::class,
        ] as $contract) {
            self::assertSame($application->make($contract), $application->make($contract), $contract);
        }

        foreach ([
            ExecutionGateContract::class,
            ContinueRunPipe::class,
            ResolveStrategy::class,
        ] as $contract) {
            self::assertNotSame($application->make($contract), $application->make($contract), $contract);
        }
    }

    public function test_facade_resolves_the_current_scoped_rick_instance(): void
    {
        $before = RickFacade::getFacadeRoot();
        self::assertInstanceOf(Rick::class, $before);
        self::assertSame($this->application()->make(Rick::class), $before);

        $this->application()->forgetScopedInstances();
        $after = RickFacade::getFacadeRoot();

        self::assertInstanceOf(Rick::class, $after);
        self::assertNotSame($before, $after);
        self::assertSame($this->application()->make(Rick::class), $after);
    }

    public function test_same_business_id_is_isolated_by_tenant_and_lock_keys_hide_both_values(): void
    {
        $context = $this->application()->make(TenantContextBase::class);
        $repository = $this->application()->make(RunRepositoryBase::class);

        foreach (['tenant-a', 'tenant-b'] as $tenantId) {
            $context->run($tenantId, function () use ($repository): void {
                $repository->add($this->newRun('shared-run'));
                self::assertSame(
                    'shared-run',
                    $repository->get(RunId::fromString('shared-run'))->id()->toString(),
                );
            });
        }

        self::assertSame(
            2,
            $this->application()->make(ConnectionInterface::class)
                ->table('rick_runs')
                ->where('id', 'shared-run')
                ->count(),
        );
        $first = QueueLock::key('run', 'tenant-a', 'shared-run');
        $second = QueueLock::key('run', 'tenant-b', 'shared-run');
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('tenant-a', $first);
        self::assertStringNotContainsString('shared-run', $first);
        self::assertMatchesRegularExpression('/^rick:run:[a-f0-9]{64}$/', $first);

        self::assertSame(1, (new ContinueRunJob('tenant-a', 'shared-run'))->middleware()[0]->releaseAfter);
        self::assertSame(
            1,
            (new ExecuteInvocationJob('tenant-a', 'shared-invocation', 'shared-run'))
                ->middleware()[0]
                ->releaseAfter,
        );
    }

    private function newRun(string $id): WorkflowRun
    {
        return WorkflowRun::start(
            RunId::fromString($id),
            new CompiledWorkflow('tenant-scope', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('resolve'),
                    'Keep tenants apart',
                    DefinitionOfDone::fromString('Each tenant has its own row'),
                ),
            ]),
            new RunInput([]),
            10,
        );
    }
}
