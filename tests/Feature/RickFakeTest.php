<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Laravel\Run;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\Support\ClaimDecisionWorkflow;
use Rick\Laravel\Tests\TestCase;

final class RickFakeTest extends TestCase
{
    public function test_fake_runs_a_normal_workflow_without_low_level_imports(): void
    {
        $fake = $this->application()->make(Rick::class)->fake();

        $fake->agent('facts', 'The claimant was in a rear-end collision.');
        $fake->agent('risk', 'Low risk.');

        $run = ClaimDecisionWorkflow::start(['claim_id' => 42]);

        $fake->assertStepRan($run, 'load-claim');
        $fake->assertStepRan($run, 'facts');
        $fake->assertStepRan($run, 'risk');
        $fake->assertAwaitingHuman($run);
        $fake->assertProviderAttempts(2);

        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);
        self::assertSame(2, $fake->gateway()->requestCount());
    }

    public function test_assert_run_recovered_from_verifies_recovery_lineage(): void
    {
        $this->application()->instance(GatewayBase::class, (new FakeGateway)->reject(retryable: false));
        $rick = $this->application()->make(Rick::class);

        $failed = $rick->run($rick->workflow('failing')->rawPrompt('Fail.')->build());
        $parentRun = Run::of($rick, $failed);

        $receipt = $parentRun->retry();
        $childRun = Run::of($rick, $receipt->run);

        $fake = $rick->fake();

        $fake->assertRunRecoveredFrom($childRun, $parentRun);

        self::assertNotNull($receipt->run->recovery);
    }
}
