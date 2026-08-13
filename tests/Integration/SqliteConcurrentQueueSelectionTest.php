<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Infrastructure\Persistence\Json\JsonInput;
use Rick\Laravel\Infrastructure\Queue\Job\ContinueRunJob;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;

final class SqliteConcurrentQueueSelectionTest extends TestCase
{
    private ?string $databasePath = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (getenv('RICK_TEST_SQLITE_QUEUE_PROFILE') !== '1') {
            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'rick-sqlite-queue-');
        if (! is_string($path)) {
            throw new RuntimeException('Unable to create the SQLite queue smoke database.');
        }
        $this->databasePath = $path;
        $app['config']->set('database.default', 'rick_sqlite_queue');
        $app['config']->set('database.connections.rick_sqlite_queue', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'journal_mode' => 'WAL',
            'busy_timeout' => 5000,
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'IMMEDIATE',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'rick_sqlite_queue',
            'table' => 'jobs',
            'queue' => 'rick-control',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('rick.queue.connection', 'database');
        $app['config']->set('rick.queue.control', 'rick-control');
        $app['config']->set('rick.queue.llm', 'rick-llm');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->enabled() || Schema::hasTable('jobs')) {
            return;
        }
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->databasePath === null) {
            return;
        }
        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_parallel_completions_and_concurrent_manual_selection_do_not_lock(): void
    {
        if (! $this->enabled()) {
            self::markTestSkipped('Set RICK_TEST_SQLITE_QUEUE_PROFILE=1 for the SQLite worker smoke.');
        }

        $gateway = (new FakeGateway)->respondUsing(
            static function (CompletionRequest $request): CompletionResponse {
                $index = JsonInput::integer(
                    $request->metadata['candidate_index'] ?? null,
                    'request.metadata.candidate_index',
                );

                return new CompletionResponse(
                    structured: ['content' => 'Queued plan '.($index + 1)],
                    provider: 'fake-sqlite',
                    model: 'fake-worker',
                    metrics: new CompletionMetrics(new TokenUsage(10 + $index, 5)),
                );
            },
        );
        $this->application()->instance(GatewayBase::class, $gateway);
        $rick = $this->application()->make(Rick::class);
        $scheduled = $rick->schedule(
            $rick->workflow('sqlite-concurrent-selection')
                ->resolve('Create three plans', 'One plan can be selected while workers are active')
                ->plan(candidates: 3)
                ->manualJudge()
                ->outputGlue('plan')
                ->build(),
        );

        $waiting = $this->workUntil($rick, $scheduled->id->toString(), RunStatus::AwaitingInput);
        $gateway->assertRequested(times: 3);
        self::assertSame(48, $rick->metrics($waiting->id)->totals->tokens->totalTokens);
        $review = $rick->pendingReview($waiting->id);
        self::assertCount(3, $review->candidates);

        for ($continuation = 0; $continuation < 5; $continuation++) {
            Queue::connection('database')->push(
                new ContinueRunJob('default', $waiting->id->toString()),
                '',
                'rick-control',
            );
        }

        for ($transition = 0; $transition < 30; $transition++) {
            if (Queue::connection('database')->size('rick-control') === 0) {
                break;
            }
            $this->artisanCommand('queue:work', [
                'connection' => 'database',
                '--queue' => 'rick-control',
                '--once' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertSuccessful();
        }

        $stillWaiting = $rick->snapshot($waiting->id);
        self::assertSame(RunStatus::AwaitingInput, $stillWaiting->status);
        self::assertSame($waiting->version, $stillWaiting->version);
        self::assertSame(0, Queue::connection('database')->size('rick-control'));

        [$process, $pipes] = $this->holdConcurrentWriteLock();
        $started = hrtime(true);
        try {
            $selection = $rick->selectCandidate($waiting->id, $review->candidates[1]->id);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $exit = proc_close($process);
        }
        self::assertSame(0, $exit);
        self::assertGreaterThanOrEqual(200, (hrtime(true) - $started) / 1_000_000);
        self::assertTrue($selection->continuationQueued);
        self::assertSame('Queued plan 2', $selection->artifact('plan')->content);

        $completed = $this->workUntil($rick, $scheduled->id->toString(), RunStatus::Completed);
        self::assertSame('Queued plan 2', $completed->output());
        self::assertSame(0, Queue::connection('database')->size('rick-control'));
        self::assertSame(0, Queue::connection('database')->size('rick-llm'));
    }

    private function workUntil(Rick $rick, string $runId, RunStatus $expected): WorkflowRunSnapshot
    {
        for ($transition = 0; $transition < 30; $transition++) {
            $this->waitForQueuedMessage();
            $this->artisanCommand('queue:work', [
                'connection' => 'database',
                '--queue' => 'rick-control,rick-llm',
                '--once' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertSuccessful();
            $run = $rick->snapshot($runId);
            if ($run->status === $expected) {
                return $run;
            }
        }

        self::fail("Run did not reach [{$expected->value}] within the worker transition limit.");
    }

    private function waitForQueuedMessage(): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $queue = Queue::connection('database');
            if ($queue->size('rick-control') + $queue->size('rick-llm') > 0) {
                return;
            }
            usleep(50_000);
        }

        self::fail('SQLite queues did not expose the expected message within 2.5 seconds.');
    }

    /** @return array{resource, array<int, resource>} */
    private function holdConcurrentWriteLock(): array
    {
        $path = $this->databasePath
            ?? throw new RuntimeException('SQLite queue smoke database is unavailable.');
        $code = <<<'PHP'
$database = new PDO('sqlite:'.$argv[1]);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('PRAGMA busy_timeout = 5000');
$database->exec('BEGIN IMMEDIATE TRANSACTION');
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
usleep(400000);
$database->exec('COMMIT');
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $path],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a concurrent SQLite writer.');
        }
        fclose($pipes[0]);
        $ready = fgets($pipes[1]);
        if ($ready !== "READY\n") {
            $error = stream_get_contents($pipes[2]);
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                fclose($pipe);
            }
            proc_close($process);

            throw new RuntimeException('Concurrent SQLite writer failed: '.$error);
        }

        return [$process, $pipes];
    }

    private function enabled(): bool
    {
        return getenv('RICK_TEST_SQLITE_QUEUE_PROFILE') === '1';
    }
}
