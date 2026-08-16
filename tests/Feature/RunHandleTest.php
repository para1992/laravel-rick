<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Laravel\Run;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\TestCase;

final class RunHandleTest extends TestCase
{
    public function test_run_handle_exposes_progress_metrics_and_snapshot(): void
    {
        $fake = (new FakeGateway)->respondMeasured(
            'Summarized.',
            null,
            new CompletionMetrics(new TokenUsage(10, 5)),
        );
        $this->app->instance(GatewayBase::class, $fake);
        $rick = $this->app->make(Rick::class);

        $snapshot = $rick->run($rick->workflow('summary')->rawPrompt('Summarize this.')->build());
        $run = Run::of($rick, $snapshot);

        self::assertSame($snapshot->id->toString(), $run->id());
        self::assertSame(RunStatus::Completed, $run->snapshot()->status);
        self::assertSame(RunStatus::Completed, $run->progress()->status);
        self::assertSame($run->progress()->current, $run->progress()->total);
        self::assertNotNull($run->metrics());
    }

    public function test_retry_creates_a_recovery_child_pointing_to_the_parent(): void
    {
        $fake = (new FakeGateway)->reject(retryable: false);
        $this->app->instance(GatewayBase::class, $fake);
        $rick = $this->app->make(Rick::class);

        $failed = $rick->run($rick->workflow('failing')->rawPrompt('Fail this.')->build());
        self::assertSame(RunStatus::Failed, $failed->status);

        $run = Run::of($rick, $failed);
        $receipt = $run->retry();

        self::assertNotNull($receipt->run->recovery);
        self::assertSame(
            $failed->id->toString(),
            $receipt->run->recovery->parentRunId->toString(),
        );
    }
}
