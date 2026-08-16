<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Closure;
use Rick\Laravel\Application\Execution\Exception\ExecutionRecordNotFoundException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\Support\ClaimDecisionWorkflow;
use Rick\Laravel\Tests\TestCase;

final class TenantIsolationForPublicApisTest extends TestCase
{
    public function test_step_and_agent_runs_do_not_leak_across_tenants(): void
    {
        $context = $this->tenantContext();
        $this->application()->forgetScopedInstances();
        $this->application()->instance(TenantContextBase::class, $context);
        $this->application()->instance(GatewayBase::class, $this->gateway());
        $rick = $this->application()->make(Rick::class);

        $first = ClaimDecisionWorkflow::start(['claim_id' => 1]);
        $firstId = $first->snapshot()->id;
        self::assertSame(RunStatus::AwaitingInput, $first->snapshot()->status);
        self::assertTrue($first->snapshot()->hasArtifact('claim'));

        $context->tenantId = 'tenant-b';
        $second = ClaimDecisionWorkflow::start(['claim_id' => 2]);
        $secondId = $second->snapshot()->id;
        self::assertSame(RunStatus::AwaitingInput, $second->snapshot()->status);
        self::assertTrue($second->snapshot()->hasArtifact('claim'));

        $context->tenantId = 'tenant-a';
        self::assertSame(1, $rick->snapshot($firstId)->artifact('claim')->payload['id']);

        try {
            $rick->snapshot($secondId);
            self::fail('Expected the tenant-b run to be invisible under tenant-a.');
        } catch (ExecutionRecordNotFoundException) {
            // The tenant-b run is not readable under tenant-a.
        }
    }

    public function test_progress_is_tenant_scoped(): void
    {
        $context = $this->tenantContext();
        $this->application()->forgetScopedInstances();
        $this->application()->instance(TenantContextBase::class, $context);
        $this->application()->instance(GatewayBase::class, $this->gateway());
        $rick = $this->application()->make(Rick::class);

        $run = ClaimDecisionWorkflow::start(['claim_id' => 9]);
        $runId = $run->snapshot()->id;
        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);

        $context->tenantId = 'tenant-b';

        try {
            $rick->snapshot($runId);
            self::fail('Expected the tenant-a run to be invisible under tenant-b.');
        } catch (ExecutionRecordNotFoundException) {
            // The run and its timeline are tenant-scoped.
        }

        try {
            $rick->timeline($runId);
            self::fail('Expected the tenant-a timeline to be invisible under tenant-b.');
        } catch (ExecutionRecordNotFoundException) {
            // The timeline is equally tenant-scoped.
        }
    }

    private function tenantContext(): MutableTenantContext
    {
        return new MutableTenantContext('tenant-a');
    }

    private function gateway(): FakeGateway
    {
        return (new FakeGateway)->respondUsing(
            static function (CompletionRequest $request): CompletionResponse {
                return match ($request->purpose) {
                    'agent:facts' => new CompletionResponse(
                        text: 'The claimant was in a rear-end collision.',
                        provider: 'fake',
                        model: 'fixture-model',
                        metrics: new CompletionMetrics(new TokenUsage(10, 5)),
                    ),
                    'agent:risk' => new CompletionResponse(
                        text: 'Low risk: no pre-existing conditions cited.',
                        provider: 'fake',
                        model: 'fixture-model',
                        metrics: new CompletionMetrics(new TokenUsage(8, 4)),
                    ),
                    default => throw new \RuntimeException('Unexpected purpose ['.$request->purpose.'].'),
                };
            },
        );
    }
}

/**
 * A mutable test tenant context. Its public id is switched between operations
 * to prove that public API reads (snapshot, timeline) are scoped by the current
 * tenant.
 */
final class MutableTenantContext implements TenantContextBase
{
    public string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function id(): string
    {
        return $this->tenantId;
    }

    public function run(string $tenantId, Closure $operation): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;
        try {
            return $operation();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
