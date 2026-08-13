<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\ContextDocument;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\ContextStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class ContextStrategy implements StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === 'context';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof ContextStep) {
            throw new LogicException('Context strategy received an incompatible step.');
        }
        $value = $run->input->get($step->inputKey);
        $content = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new ImmediateStepPlan(StepOutcome::contextsAdded([
            new ContextDocument($step->inputKey, $content, strlen($content), strlen($content), false),
        ], artifacts: [
            new Artifact(
                $step->inputKey,
                ArtifactType::fromString('context'),
                $content,
                is_array($value) ? $value : [],
            ),
        ]));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Context is immediate and cannot reduce invocations.');
    }
}
