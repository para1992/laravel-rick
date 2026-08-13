<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Queue;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\PayloadProtectorBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Event\WorkflowCreated;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Outbox\OutboxRelay;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRow;
use Rick\Laravel\Infrastructure\Persistence\Json\DomainEventCodec;
use Rick\Laravel\Infrastructure\Persistence\LaravelTransaction;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class TransactionOutboxReliabilityTest extends TestCase
{
    public function test_after_commit_observes_root_and_nested_commit_and_rollback(): void
    {
        $database = $this->database();
        $transactions = new LaravelTransaction($database);
        $calls = [];

        $database->beginTransaction();
        $transactions->afterCommit(static function () use (&$calls): void {
            $calls[] = 'root';
        });
        self::assertSame([], $calls);
        $database->commit();
        self::assertSame(['root'], $calls);

        $database->beginTransaction();
        $database->beginTransaction();
        $transactions->afterCommit(static function () use (&$calls): void {
            $calls[] = 'nested';
        });
        $database->commit();
        self::assertSame(['root'], $calls);
        $database->commit();
        self::assertSame(['root', 'nested'], $calls);

        $database->beginTransaction();
        $transactions->afterCommit(static function () use (&$calls): void {
            $calls[] = 'rolled-back-root';
        });
        $database->rollBack();
        self::assertSame(['root', 'nested'], $calls);

        $database->beginTransaction();
        $database->beginTransaction();
        $transactions->afterCommit(static function () use (&$calls): void {
            $calls[] = 'rolled-back-nested';
        });
        $database->rollBack();
        $database->commit();
        self::assertSame(['root', 'nested'], $calls);
    }

    public function test_after_commit_callback_failures_are_isolated(): void
    {
        $database = $this->database();
        $transactions = new LaravelTransaction($database);
        $calls = [];

        $database->beginTransaction();
        $transactions->afterCommit(static function (): void {
            throw new RuntimeException('callback failure');
        });
        $transactions->afterCommit(static function () use (&$calls): void {
            $calls[] = 'continued';
        });
        $database->commit();

        self::assertSame(['continued'], $calls);
    }

    public function test_outer_rollback_atomically_discards_run_outbox_and_queue_handoff(): void
    {
        Queue::fake();
        $database = $this->database();
        $rick = $this->application()->make(Rick::class);
        $runId = null;

        try {
            $database->transaction(function () use ($rick, &$runId): void {
                $scheduled = $rick->schedule(
                    $rick->workflow('atomic-rollback')
                        ->resolve('Rollback', 'Nothing is committed')
                        ->build(),
                );
                $runId = $scheduled->id->toString();

                throw new RuntimeException('rollback outer transaction');
            });
        } catch (RuntimeException $error) {
            self::assertSame('rollback outer transaction', $error->getMessage());
        }

        self::assertIsString($runId);
        self::assertSame(0, $database->table('rick_runs')->where('id', $runId)->count());
        self::assertSame(0, $database->table('rick_outbox')->where('run_id', $runId)->count());
        Queue::assertNothingPushed();
    }

    public function test_synchronous_execution_records_events_but_no_queue_intents(): void
    {
        Queue::fake();
        $rick = $this->application()->make(Rick::class);
        $run = $rick->run(
            $rick->workflow('sync-no-intents')
                ->resolve('Complete inline', 'No queue handoff exists')
                ->build(),
        );

        self::assertGreaterThan(
            0,
            $this->database()->table('rick_outbox')
                ->where('run_id', $run->id->toString())
                ->where('kind', 'domain_event')
                ->count(),
        );
        self::assertSame(
            0,
            $this->database()->table('rick_outbox')
                ->where('run_id', $run->id->toString())
                ->whereIn('kind', ['continue_run', 'execute_invocation'])
                ->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_broker_failure_is_safely_deferred_and_later_retried(): void
    {
        $this->addRun('broker-retry');
        $this->insertOutbox('broker-row', 'continue_run', 'broker-retry');
        $bus = $this->createMock(BusDispatcher::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('broker-secret-marker'));

        $failed = $this->relay($bus)->relay();
        $row = $this->outboxRow('broker-row');

        self::assertSame(1, $failed->deferred);
        self::assertSame('pending', $row->string('status'));
        self::assertSame(1, $row->integer('attempts'));
        self::assertSame('outbox_delivery_failed', $row->string('last_error_code'));
        self::assertStringNotContainsString('broker-secret-marker', $row->string('last_error_message'));

        $this->database()->table('rick_outbox')->where('id', 'broker-row')->update([
            'available_at' => new DateTimeImmutable('-1 second'),
        ]);
        Queue::fake();
        $delivered = $this->relay($this->application()->make(BusDispatcher::class))->relay();

        self::assertSame(1, $delivered->delivered);
        self::assertSame('delivered', $this->outboxRow('broker-row')->string('status'));
    }

    public function test_crash_after_dispatch_is_recovered_as_an_at_least_once_duplicate(): void
    {
        $this->addRun('duplicate-run');
        $this->insertOutbox('duplicate-row', 'continue_run', 'duplicate-run');
        $dispatches = 0;
        $database = $this->database();
        $bus = $this->createMock(BusDispatcher::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (mixed $job) use (&$dispatches, $database): mixed {
                $dispatches++;
                if ($dispatches === 1) {
                    $database->table('rick_outbox')->where('id', 'duplicate-row')->update([
                        'version' => $database->raw('version + 1'),
                    ]);
                }

                return $job;
            },
        );

        $this->relay($bus)->relay();
        self::assertSame('delivering', $this->outboxRow('duplicate-row')->string('status'));

        $database->table('rick_outbox')->where('id', 'duplicate-row')->update([
            'lease_expires_at' => new DateTimeImmutable('-1 second'),
        ]);
        $this->relay($bus)->relay();

        self::assertSame(2, $dispatches);
        self::assertSame('delivered', $this->outboxRow('duplicate-row')->string('status'));
    }

    public function test_an_active_lease_prevents_a_concurrent_claim(): void
    {
        $this->addRun('concurrent-run');
        $this->insertOutbox('concurrent-row', 'continue_run', 'concurrent-run');
        $dispatches = 0;
        $secondClaimed = null;
        $bus = $this->createMock(BusDispatcher::class);
        $firstRelay = $this->relay($bus);
        $secondRelay = $this->relay($bus);
        $bus->method('dispatch')->willReturnCallback(
            static function (mixed $job) use (&$dispatches, &$secondClaimed, $secondRelay): mixed {
                $dispatches++;
                $secondClaimed = $secondRelay->relay()->claimed;

                return $job;
            },
        );

        $firstRelay->relay();

        self::assertSame(1, $dispatches);
        self::assertSame(0, $secondClaimed);
        self::assertSame('delivered', $this->outboxRow('concurrent-row')->string('status'));
    }

    public function test_listener_failure_retries_and_poison_payload_enters_quarantine(): void
    {
        $this->addRun('event-run');
        $event = new WorkflowCreated(
            RunId::fromString('event-run'),
            'event-workflow',
            '1.0.0',
            new DateTimeImmutable('2026-07-31T00:00:00.123456Z'),
        );
        $codec = $this->application()->make(DomainEventCodec::class);
        $payload = $this->application()->make(PayloadProtectorBase::class)->protect($codec->encode($event));
        $this->insertOutbox(
            'event-row',
            'domain_event',
            'event-run',
            eventType: $codec->type($event),
            payload: $payload,
        );
        $events = $this->createMock(EventDispatcher::class);
        $events->method('dispatch')->willThrowException(new RuntimeException('listener-secret-marker'));

        $result = $this->relay($this->application()->make(BusDispatcher::class), $events)->relay();
        $eventRow = $this->outboxRow('event-row');
        self::assertSame(1, $result->deferred);
        self::assertSame('pending', $eventRow->string('status'));
        self::assertStringNotContainsString('listener-secret-marker', $eventRow->string('last_error_message'));

        $this->insertOutbox('poison-row', 'unsupported_kind', 'event-run');
        $poisoned = $this->relay($this->application()->make(BusDispatcher::class))->relay();
        $poisonRow = $this->outboxRow('poison-row');
        self::assertSame(1, $poisoned->failed);
        self::assertSame('failed', $poisonRow->string('status'));
        self::assertSame('outbox_payload_invalid', $poisonRow->string('last_error_code'));
        self::assertSame(2, $poisonRow->integer('version'));
    }

    public function test_domain_event_codec_round_trips_and_rejects_future_versions(): void
    {
        $event = new WorkflowCreated(
            RunId::fromString('codec-run'),
            'codec-workflow',
            '1.0.0',
            new DateTimeImmutable('2026-07-31T00:00:00.123456Z'),
        );
        $codec = $this->application()->make(DomainEventCodec::class);
        $payload = $codec->encode($event);

        $decoded = $codec->decode($codec->type($event), $payload);
        self::assertInstanceOf(WorkflowCreated::class, $decoded);
        self::assertEquals($event, $decoded);
        self::assertSame('123456', $decoded->occurredAt->format('u'));

        $future = str_replace('"schema_version":1', '"schema_version":2', $payload);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported domain-event schema version');
        $codec->decode($codec->type($event), $future);
    }

    private function database(): Connection
    {
        return $this->application()->make(DatabaseManager::class)->connection();
    }

    private function addRun(string $id): void
    {
        $this->application()->make(RunRepositoryBase::class)->add(WorkflowRun::start(
            RunId::fromString($id),
            new CompiledWorkflow('outbox', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('resolve'),
                    'Relay safely',
                    DefinitionOfDone::fromString('The row is delivered'),
                ),
            ]),
            new RunInput([]),
            10,
        ));
    }

    private function insertOutbox(
        string $id,
        string $kind,
        string $runId,
        ?string $eventType = null,
        ?string $payload = null,
    ): void {
        $now = new DateTimeImmutable('-1 second');
        $this->database()->table('rick_outbox')->insert([
            'tenant_id' => 'default',
            'id' => $id,
            'kind' => $kind,
            'run_id' => $runId,
            'invocation_id' => null,
            'event_type' => $eventType,
            'payload' => $payload,
            'deduplication_key' => hash('sha256', $id),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'lease_token' => null,
            'lease_expires_at' => null,
            'delivered_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function relay(BusDispatcher $bus, ?EventDispatcher $events = null): OutboxRelay
    {
        return new OutboxRelay(
            $this->database(),
            $bus,
            $events ?? $this->application()->make(EventDispatcher::class),
            $this->application()->make(DomainEventCodec::class),
            $this->application()->make(PayloadProtectorBase::class),
            $this->application()->make(TenantContextBase::class),
            $this->application()->make(ClockBase::class),
            $this->application()->make(IdGeneratorBase::class),
            batchSize: 100,
            leaseSeconds: 60,
            maxAttempts: 3,
            retryBaseSeconds: 1,
            retryMaxSeconds: 4,
        );
    }

    private function outboxRow(string $id): DatabaseRow
    {
        $row = $this->database()->table('rick_outbox')->where('id', $id)->first()
            ?? throw new RuntimeException("Missing outbox row [{$id}].");

        return DatabaseRow::from($row);
    }
}
