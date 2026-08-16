<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use Laravel\Ai\Contracts\Agent;
use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Agent\AgentRequestFactory;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\AgentStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

/**
 * Adapts a Laravel AI agent step into a single auditable provider call.
 *
 * The non-negotiable accounting rule: the agent is adapted into EXACTLY ONE
 * CompletionRequest that flows through InvocationStepPlan -> ExecuteInvocationPipe
 * -> GatewayBase::complete(), so provider-attempt accounting, token/cost metrics,
 * budget enforcement, tenant scoping, recovery, and indeterminate-outcome
 * handling all apply. The user agent's prompt() transport is never invoked.
 */
final readonly class AgentStepStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(private AgentRequestFactory $agents) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::agent()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof AgentStep) {
            throw new LogicException('Agent strategy received an incompatible step.');
        }

        if (! is_a($step->agentClass, Agent::class, true)) {
            throw new LogicException(sprintf(
                'Agent step [%s] references a class [%s] that does not implement %s.',
                $step->id()->toString(),
                $step->agentClass,
                Agent::class,
            ));
        }

        $prompt = $step->prompt ?? $this->defaultPrompt($run);

        $request = $this->agents->create(
            $step->agentClass,
            $step->id()->toString(),
            $prompt,
            $step->modelPolicy,
            ['agent_version' => $step->agentVersion],
        );

        return new InvocationStepPlan([$request]);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof AgentStep) {
            throw new LogicException('Agent strategy received an incompatible step.');
        }

        $response = InvocationResponses::successful($outcomes)[0];
        $key = $step->id()->toString();
        $structured = $response->structured;

        $artifact = new Artifact(
            $key,
            ArtifactType::fromString('agent'),
            $structured !== null
                ? json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : $response->text,
            $structured ?? [],
        );

        return StepOutcome::artifactsProduced(
            [$artifact],
            metadata: [
                'agent_class' => $step->agentClass,
                'agent_alias' => $key,
            ],
        );
    }

    private function defaultPrompt(WorkflowRunSnapshot $run): string
    {
        return json_encode(
            [
                'input' => $run->input->toArray(),
                'artifacts' => $this->artifactProjection($run),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactProjection(WorkflowRunSnapshot $run): array
    {
        $projection = [];
        foreach ($run->artifacts as $artifact) {
            $projection[$artifact->key] = $artifact->payload !== [] ? $artifact->payload : $artifact->content;
        }

        return $projection;
    }
}
