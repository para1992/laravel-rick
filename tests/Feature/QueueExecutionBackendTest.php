<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Infrastructure\Queue\Job\ExecuteInvocationJob;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class QueueExecutionBackendTest extends TestCase
{
    #[DataProvider('queueConnections')]
    public function test_outbox_relay_preserves_the_configured_queue_connection(string $connection): void
    {
        Queue::fake();
        $this->application()->forgetScopedInstances();
        $this->application()->instance(OutboxRelay::class, new OutboxRelay(
            $this->connection(),
            $this->application()->make(BusDispatcher::class),
            $this->application()->make(EventDispatcher::class),
            $this->application()->make(DomainEventCodec::class),
            $this->application()->make(PayloadProtectorBase::class),
            $this->application()->make(TenantContextBase::class),
            $this->application()->make(ClockBase::class),
            $this->application()->make(IdGeneratorBase::class),
            queueConnection: $connection,
            controlQueue: 'rick-control',
            llmQueue: 'rick-llm',
        ));

        $runId = RunId::fromString('queue-'.$connection);
        $this->application()->make(RunRepositoryBase::class)->add($this->newRun($runId));
        $backend = $this->application()->make(ExecutionBackendBase::class);
        $invocationId = InvocationId::fromString('invocation-'.$connection);

        $backend->continue($runId, 1);
        $backend->invoke($invocationId, $runId, 2);

        Queue::assertPushed(
            ContinueRunJob::class,
            static fn (ContinueRunJob $job): bool => $job->connection === $connection
                && $job->queue === 'rick-control'
                && $job->tenantId === 'default',
        );
        Queue::assertPushed(
            ExecuteInvocationJob::class,
            static fn (ExecuteInvocationJob $job): bool => $job->connection === $connection
                && $job->queue === 'rick-llm'
                && $job->tenantId === 'default',
        );
        self::assertSame(
            2,
            $this->connection()->table('rick_outbox')->where('status', 'delivered')->count(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function queueConnections(): iterable
    {
        yield 'database' => ['database'];
        yield 'redis' => ['redis'];
        yield 'sqs' => ['sqs'];
    }

    public function test_continuations_are_deduplicated_by_source_invocation_transition(): void
    {
        Queue::fake();
        $runId = RunId::fromString('parallel-continuations');
        $this->application()->make(RunRepositoryBase::class)->add($this->newRun($runId));
        $backend = $this->application()->make(ExecutionBackendBase::class);
        $first = InvocationId::fromString('parallel-first');
        $second = InvocationId::fromString('parallel-second');

        $backend->continue($runId, 2, $first);
        $backend->continue($runId, 2, $first);
        $backend->continue($runId, 2, $second);

        Queue::assertPushed(ContinueRunJob::class, 2);
        self::assertSame(
            2,
            $this->connection()
                ->table('rick_outbox')
                ->where('kind', 'continue_run')
                ->where('run_id', $runId->toString())
                ->count(),
        );
    }

    public function test_parallel_queue_invocations_are_order_stable_and_duplicate_delivery_is_idempotent(): void
    {
        Queue::fake();
        $gateway = new class implements GatewayBase
        {
            public int $calls = 0;

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $output = $request->metadata['output_key'] ?? null;
                if (! is_string($output)) {
                    throw new \RuntimeException('Parallel request output key is missing.');
                }
                $this->calls++;

                return new CompletionResponse(
                    text: 'result:'.$output,
                    provider: 'fake',
                    model: 'fake-parallel',
                );
            }
        };
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $scheduled = $rick->schedule(
            $rick->workflow('stable-parallel-queue')
                ->resolve('Run independent analyses.', 'Every analysis is joined in declaration order.')
                ->parallel([
                    new OperationCall('first', 'rick.text', null, [], 'first'),
                    new OperationCall('second', 'rick.text', null, [], 'second'),
                    new OperationCall('third', 'rick.text', null, [], 'third'),
                ])
                ->join(['first', 'second', 'third'], 'combined')
                ->outputGlue('combined')
                ->build(),
        );
        $handler = $this->application()->make(Handler::class);
        $tenant = $this->application()->make(TenantContextBase::class);
        $continuation = new ContinueRunJob('default', $scheduled->id->toString());

        $continuation->handle($handler, $tenant);
        $continuation->handle($handler, $tenant);

        $invocationIds = $this->connection()
            ->table('rick_llm_invocations')
            ->where('run_id', $scheduled->id->toString())
            ->orderByDesc('invocation_index')
            ->pluck('id')
            ->all();
        self::assertCount(3, $invocationIds);

        foreach ($invocationIds as $invocationId) {
            self::assertIsString($invocationId);
            (new ExecuteInvocationJob(
                'default',
                $invocationId,
                $scheduled->id->toString(),
            ))->handle($handler, $tenant);
        }

        $duplicateId = $invocationIds[0];
        self::assertIsString($duplicateId);
        (new ExecuteInvocationJob(
            'default',
            $duplicateId,
            $scheduled->id->toString(),
        ))->handle($handler, $tenant);
        self::assertSame(3, $gateway->calls);

        for ($transition = 0; $transition < 5; $transition++) {
            if ($rick->snapshot($scheduled->id)->status->isTerminal()) {
                break;
            }
            $continuation->handle($handler, $tenant);
        }

        $completed = $rick->snapshot($scheduled->id);
        self::assertSame(RunStatus::Completed, $completed->status);
        self::assertSame(
            "result:first\n\nresult:second\n\nresult:third",
            $completed->output(),
        );
        self::assertSame(
            3,
            $this->connection()
                ->table('rick_invocation_attempts')
                ->where('run_id', $scheduled->id->toString())
                ->count(),
        );
        $continuationInvocationIds = $this->connection()
            ->table('rick_outbox')
            ->where('kind', 'continue_run')
            ->where('run_id', $scheduled->id->toString())
            ->whereNotNull('invocation_id')
            ->pluck('invocation_id');
        self::assertCount(3, $continuationInvocationIds);
        self::assertCount(3, $continuationInvocationIds->unique());
    }

    private function newRun(RunId $id): WorkflowRun
    {
        return WorkflowRun::start(
            $id,
            new CompiledWorkflow('queue', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('resolve'),
                    'Queue safely',
                    DefinitionOfDone::fromString('The handoff is durable'),
                ),
            ]),
            new RunInput([]),
            10,
        );
    }

    private function connection(): Connection
    {
        return $this->application()->make(DatabaseManager::class)->connection();
    }
}
