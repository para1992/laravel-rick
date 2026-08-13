<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Persistence\RunPruner;
use Rick\Laravel\Tests\TestCase;

final class RetentionTest extends TestCase
{
    public function test_pruner_honours_tenant_cutoff_status_batch_cascade_outbox_and_dry_run(): void
    {
        $database = $this->database();
        if ($database->getDriverName() === 'sqlite') {
            $database->statement('PRAGMA foreign_keys = ON');
        }
        $old = new DateTimeImmutable('-60 days');
        $recent = new DateTimeImmutable('-1 day');
        $cutoff = new DateTimeImmutable('-30 days');

        foreach (['old-a', 'old-b', 'protected', 'recent', 'created'] as $id) {
            $this->addRun($id);
        }
        $database->table('rick_runs')->whereIn('id', ['old-a', 'old-b', 'protected'])
            ->update(['status' => 'completed', 'updated_at' => $old]);
        $database->table('rick_runs')->where('id', 'recent')
            ->update(['status' => 'failed', 'updated_at' => $recent]);
        $database->table('rick_runs')->where('id', 'created')
            ->update(['status' => 'created', 'updated_at' => $old]);

        $this->application()->make(TenantContextBase::class)->run('tenant-b', function () use ($database, $old): void {
            $this->addRun('old-a');
            $database->table('rick_runs')
                ->where('tenant_id', 'tenant-b')
                ->where('id', 'old-a')
                ->update(['status' => 'completed', 'updated_at' => $old]);
        });

        $database->table('rick_step_executions')->insert([
            'tenant_id' => 'default',
            'id' => 'old-a-step',
            'run_id' => 'old-a',
            'step_id' => 'resolve',
            'sequence' => 1,
            'status' => 'completed',
            'expected_invocations' => 0,
            'dispatched_invocations' => 0,
            'version' => 1,
            'error_code' => null,
            'error_message' => null,
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $this->insertPendingOutbox('protected', $old);

        $pruner = $this->application()->make(RunPruner::class);
        $dryRun = $pruner->prune($cutoff, 1, true);
        self::assertSame(1, $dryRun->matched);
        self::assertSame(0, $dryRun->deleted);
        self::assertSame(['old-a'], $dryRun->runIds);
        self::assertSame(1, $database->table('rick_runs')->where('id', 'old-a')->where('tenant_id', 'default')->count());

        $first = $pruner->prune($cutoff, 1, false);
        self::assertSame(['old-a'], $first->runIds);
        self::assertSame(1, $first->deleted);
        self::assertSame(0, $database->table('rick_step_executions')->where('id', 'old-a-step')->count());

        $remaining = $pruner->prune($cutoff, 100, false);
        self::assertSame(['old-b'], $remaining->runIds);
        self::assertSame(1, $remaining->deleted);
        self::assertSame(1, $database->table('rick_runs')->where('id', 'protected')->count());
        self::assertSame(1, $database->table('rick_runs')->where('id', 'recent')->count());
        self::assertSame(1, $database->table('rick_runs')->where('id', 'created')->count());
        self::assertSame(
            1,
            $database->table('rick_runs')->where('tenant_id', 'tenant-b')->where('id', 'old-a')->count(),
        );
    }

    public function test_prune_command_requires_an_explicit_cutoff_and_supports_dry_run(): void
    {
        $this->addRun('command-run');
        $this->database()->table('rick_runs')->where('id', 'command-run')->update([
            'status' => 'cancelled',
            'updated_at' => new DateTimeImmutable('-60 days'),
        ]);

        $this->artisanCommand('rick:prune', [
            '--before' => (new DateTimeImmutable('-30 days'))->format(DATE_ATOM),
            '--dry-run' => true,
        ])->expectsOutputToContain('matched 1 run(s)')->assertSuccessful();

        self::assertSame(1, $this->database()->table('rick_runs')->where('id', 'command-run')->count());
    }

    private function database(): Connection
    {
        return $this->application()->make(DatabaseManager::class)->connection();
    }

    private function addRun(string $id): void
    {
        $this->application()->make(RunRepositoryBase::class)->add(WorkflowRun::start(
            RunId::fromString($id),
            new CompiledWorkflow('retention', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('resolve'),
                    'Retain safely',
                    DefinitionOfDone::fromString('Only eligible rows are deleted'),
                ),
            ]),
            new RunInput([]),
            10,
        ));
    }

    private function insertPendingOutbox(string $runId, DateTimeImmutable $timestamp): void
    {
        $this->database()->table('rick_outbox')->insert([
            'tenant_id' => 'default',
            'id' => 'protected-outbox',
            'kind' => 'continue_run',
            'run_id' => $runId,
            'invocation_id' => null,
            'event_type' => null,
            'payload' => null,
            'deduplication_key' => hash('sha256', 'protected-outbox'),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $timestamp,
            'lease_token' => null,
            'lease_expires_at' => null,
            'delivered_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'version' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
