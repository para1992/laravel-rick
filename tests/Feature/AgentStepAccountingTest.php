<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Laravel\Ai\Contracts\HasTools;
use Rick\Laravel\Application\Execution\Exception\UnsupportedAgentCapabilityException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\Support\Agents\ExtractClaimFacts;
use Rick\Laravel\Tests\Support\Agents\FixtureAgent;
use Rick\Laravel\Tests\Support\ClaimDecisionWorkflow;
use Rick\Laravel\Tests\TestCase;

final class AgentStepAccountingTest extends TestCase
{
    public function test_agent_step_records_provider_attempts_and_metrics(): void
    {
        $fake = $this->gateway();
        $this->application()->instance(GatewayBase::class, $fake);
        $rick = $this->application()->make(Rick::class);

        $run = ClaimDecisionWorkflow::start(['claim_id' => 7]);

        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);
        self::assertSame(2, $fake->requestCount());

        $metrics = $rick->metrics($run->snapshot()->id);
        self::assertSame(2, $metrics->totals->calls);
        self::assertSame(2, $metrics->totals->providerRequests);
    }

    /**
     * An agent step is adapted into exactly one audited provider request, and
     * the cost reported by the provider flows through the invocation accounting
     * into the run's typed metrics. This proves the metric path is exercised
     * without needing a live pricing catalog.
     */
    public function test_agent_step_metrics_record_provider_cost(): void
    {
        $fake = (new FakeGateway)->respondMeasured(
            'The claimant reported a rear-end collision.',
            null,
            new CompletionMetrics(
                new TokenUsage(10, 5),
                cost: InvocationCost::fromUsd('0.02'),
            ),
        );
        $this->application()->instance(GatewayBase::class, $fake);
        $rick = $this->application()->make(Rick::class);

        $run = $rick->run($rick->workflow('measured-agent')
            ->agent(ExtractClaimFacts::class, as: 'facts')
            ->build());

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame('0.02', $rick->metrics($run->id)->totals->cost->toUsdDecimal());
    }

    /**
     * An agent that declares tools can issue multiple unobservable provider
     * requests, so it is rejected during planning before any provider call or
     * run-state commit. The rejection surfaces directly as
     * UnsupportedAgentCapabilityException (a failed run is never persisted for
     * it), which is the deterministic "fails loudly" observable.
     */
    public function test_agent_step_fails_loudly_for_unsupported_capabilities(): void
    {
        $this->application()->instance(GatewayBase::class, (new FakeGateway)->reject(retryable: false));
        $rick = $this->application()->make(Rick::class);

        try {
            $rick->run($rick->workflow('tools-agent')
                ->agent(ToolWieldingAgent::class, as: 'tools')
                ->build());
            self::fail('Expected the tool-bearing agent to be rejected.');
        } catch (UnsupportedAgentCapabilityException $error) {
            self::assertSame(HasTools::class, $error->capability);
            self::assertStringContainsString(HasTools::class, $error->getMessage());
        }
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

final class ToolWieldingAgent extends FixtureAgent implements HasTools
{
    public function instructions(): string
    {
        return 'Use the search tool to answer.';
    }

    public function tools(): iterable
    {
        return [];
    }
}
