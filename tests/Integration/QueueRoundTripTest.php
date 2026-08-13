<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rick\Laravel\Application\Compilation\Support\Builder\ParallelBuilder;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class QueueRoundTripTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $driver = getenv('RICK_TEST_QUEUE_DRIVER');
        if (! is_string($driver) || $driver === '') {
            return;
        }

        $app['config']->set('queue.default', $driver);
        $app['config']->set('rick.queue.connection', $driver);
        if ($driver === 'database') {
            $app['config']->set('queue.connections.database', [
                'driver' => 'database',
                'connection' => null,
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ]);
        } elseif ($driver === 'redis') {
            $app['config']->set('database.redis.client', 'predis');
            $app['config']->set('database.redis.default', [
                'host' => self::environment('RICK_TEST_REDIS_HOST', '127.0.0.1'),
                'password' => null,
                'port' => self::environment('RICK_TEST_REDIS_PORT', '6379'),
                'database' => '0',
            ]);
            $app['config']->set('queue.connections.redis', [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'default',
                'retry_after' => 90,
                'block_for' => null,
                'after_commit' => false,
            ]);
        } elseif ($driver === 'sqs') {
            $endpoint = self::environment('RICK_TEST_SQS_ENDPOINT', 'http://127.0.0.1:4566');
            $app['config']->set('queue.connections.sqs', [
                'driver' => 'sqs',
                'key' => 'test',
                'secret' => 'test',
                'prefix' => $endpoint.'/000000000000',
                'queue' => 'default',
                'suffix' => '',
                'region' => 'us-east-1',
                'after_commit' => false,
                'endpoint' => $endpoint,
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->driver() === 'database' && ! Schema::hasTable('jobs')) {
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
    }

    public function test_real_queue_driver_round_trip_completes_a_parallel_scheduled_run(): void
    {
        $driver = $this->driver();
        if ($driver === null) {
            self::markTestSkipped('Set RICK_TEST_QUEUE_DRIVER for a real queue round trip.');
        }
        if (! in_array($driver, ['database', 'redis', 'sqs'], true)) {
            self::fail("Unsupported queue integration driver [{$driver}].");
        }

        $this->application()->instance(GatewayBase::class, new class implements GatewayBase
        {
            public function complete(CompletionRequest $request): CompletionResponse
            {
                $output = $request->metadata['output_key'] ?? null;
                if (! is_string($output)) {
                    throw new \RuntimeException('Parallel request output key is missing.');
                }

                return new CompletionResponse(
                    text: 'queue:'.$output,
                    provider: 'fake',
                    model: 'fake-queue',
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $scheduled = $rick->schedule(
            $rick->workflow('queue-round-trip')
                ->resolve('Complete parallel work from workers', 'Every parallel result is joined')
                ->parallel(static fn (ParallelBuilder $parallel): ParallelBuilder => $parallel
                    ->operation('first', 'rick.text', 'first')
                    ->operation('second', 'rick.text', 'second')
                    ->operation('third', 'rick.text', 'third'))
                ->join(['first', 'second', 'third'], 'combined')
                ->outputGlue('combined')
                ->build(),
        );

        $this->waitForQueuedMessage($driver);
        for ($transition = 0; $transition < 20; $transition++) {
            $this->artisanCommand('queue:work', [
                'connection' => $driver,
                '--queue' => 'default',
                '--once' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertSuccessful();
            if ($rick->snapshot($scheduled->id)->status->isTerminal()) {
                break;
            }
            $this->waitForQueuedMessage($driver);
        }

        $completed = $rick->snapshot($scheduled->id);
        self::assertSame(RunStatus::Completed, $completed->status);
        self::assertSame(
            "queue:first\n\nqueue:second\n\nqueue:third",
            $completed->output(),
        );
    }

    private function driver(): ?string
    {
        $driver = getenv('RICK_TEST_QUEUE_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    private function waitForQueuedMessage(string $driver): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (Queue::connection($driver)->size('default') > 0) {
                return;
            }
            usleep(250_000);
        }

        self::fail("Queue [{$driver}] did not expose the expected message within five seconds.");
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
