<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use JsonException;
use LogicException;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Domain\Execution\Interface\ExternalInputSubmissionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\AwaitingExternalInputPlan;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\AwaitHumanStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class AwaitHumanStrategy implements ExternalInputSubmissionBase, StepStrategyBase
{
    public function __construct(private JsonSchemaValidatorBase $schemas) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'await_human';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        return $step instanceof AwaitHumanStep
            ? new AwaitingExternalInputPlan($step->inputKey, $step->prompt, $step->schema)
            : throw new LogicException('Await-human strategy received an incompatible step.');
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Human approval response is reduced through SubmitInputRequest.');
    }

    public function submit(
        StepBase $step,
        WorkflowRunSnapshot $run,
        string $key,
        mixed $value,
    ): StepOutcome {
        if (! $step instanceof AwaitHumanStep) {
            throw new LogicException('Await-human strategy received an incompatible step.');
        }
        if ($key !== $step->inputKey) {
            throw new LogicException(sprintf(
                'Approval input key [%s] does not match pending key [%s].',
                $key,
                $step->inputKey,
            ));
        }
        if ($step->schema !== null) {
            $this->schemas->assert($step->schema, $value);
        }

        try {
            $content = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $error) {
            throw new LogicException('Approval response must be JSON serializable.', previous: $error);
        }

        return StepOutcome::artifactsProduced([
            new Artifact(
                $step->inputKey,
                $step->artifactType,
                $content,
                is_array($value) ? $value : ['value' => $value],
                ['source' => 'human_approval'],
            ),
        ], ['input_key' => $step->inputKey]);
    }
}
