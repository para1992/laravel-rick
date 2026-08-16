<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;
use Rick\Laravel\Testing\FakeGateway;
use Rick\Laravel\Tests\Support\ClaimDecisionWorkflow;
use Rick\Laravel\Tests\TestCase;

final class WorkflowClassTest extends TestCase
{
    public function test_claim_decision_workflow_starts_and_runs_to_the_human_barrier(): void
    {
        $this->application()->instance(GatewayBase::class, $this->gateway());

        $run = ClaimDecisionWorkflow::start(['claim_id' => 42]);

        self::assertSame(RunStatus::AwaitingInput, $run->snapshot()->status);
        self::assertTrue($run->snapshot()->hasArtifact('claim'));
        self::assertTrue($run->snapshot()->hasArtifact('facts'));
        self::assertTrue($run->snapshot()->hasArtifact('risk'));
        self::assertSame(42, $run->snapshot()->artifact('claim')->payload['id']);
        self::assertStringContainsString('rear-end', $run->snapshot()->artifact('facts')->content);
        self::assertStringContainsString('Low risk', $run->snapshot()->artifact('risk')->content);
        self::assertSame(RunStatus::AwaitingInput, $run->progress()->status);
        self::assertNotNull($run->progress()->stepId);
    }

    public function test_workflow_definition_compiles_with_stable_identity(): void
    {
        $definition = ClaimDecisionWorkflow::definition();

        self::assertInstanceOf(WorkflowDefinition::class, $definition);
        self::assertSame('claim-decision', $definition->name);
        self::assertSame('1.0.0', $definition->version);
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
                    default => throw new LogicException('Unexpected purpose ['.$request->purpose.'].'),
                };
            },
        );
    }
}
