<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\JoinStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class JoinStrategy implements StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === 'join';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof JoinStep) {
            throw new LogicException('Join strategy received an incompatible step.');
        }
        $artifacts = array_map(fn (string $key): Artifact => $run->artifact($key), $step->inputKeys);
        $content = $step->mode === 'array'
            ? json_encode(array_map(static fn (Artifact $item): array => $item->toArray(), $artifacts), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : implode($step->separator, array_map(static fn (Artifact $item): string => $item->content, $artifacts));

        return new ImmediateStepPlan(StepOutcome::artifactsProduced([
            new Artifact($step->outputKey, ArtifactType::fromString('joined'), $content),
        ]));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Join is immediate and cannot reduce invocations.');
    }
}
