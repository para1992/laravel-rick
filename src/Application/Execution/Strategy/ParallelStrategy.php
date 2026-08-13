<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\Step\ParallelStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class ParallelStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(private CompletionRequestFactory $requests) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'parallel';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof ParallelStep) {
            throw new LogicException('Parallel strategy received an incompatible step.');
        }

        return new InvocationStepPlan(array_map(
            function (OperationCall $call) use ($run): CompletionRequest {
                $inputs = [];
                foreach ($call->inputKeys as $key) {
                    $inputs[$key] = $run->artifact($key)->toArray();
                }

                return $this->requests->create(
                    'rick.step.parallel',
                    'Execute '.$call->operationId.".\n"
                        .json_encode(['inputs' => $inputs, 'parameters' => $call->parameters], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ResponseContract::Text,
                    $call->operationId,
                    metadata: ['output_key' => $call->outputKey],
                );
            },
            $step->calls,
        ));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof ParallelStep) {
            throw new LogicException('Parallel strategy received an incompatible step.');
        }
        $artifacts = [];
        foreach (InvocationResponses::successfulOutcomes($outcomes) as $outcome) {
            $response = $outcome->response
                ?? throw new LogicException('Succeeded parallel invocation has no response.');
            $call = $step->calls[$outcome->originalIndex]
                ?? throw new LogicException('Parallel invocation index has no matching operation call.');
            $artifacts[] = new Artifact(
                $call->outputKey,
                ArtifactType::fromString('operation'),
                $response->text,
            );
        }

        return StepOutcome::artifactsProduced($artifacts);
    }
}
