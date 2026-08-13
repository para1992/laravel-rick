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
use Rick\Laravel\Domain\Workflow\Step\BranchStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class BranchStrategy implements StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === 'branch';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof BranchStep) {
            throw new LogicException('Branch strategy received an incompatible step.');
        }
        $source = $run->artifact($step->conditionArtifact);
        $value = self::read($source->payload !== [] ? $source->payload : $source->content, $step->path);
        $matches = match ($step->operator) {
            'equals' => $value === $step->expected,
            'not_equals' => $value !== $step->expected,
            'contains' => is_string($value)
                && is_string($step->expected)
                && str_contains($value, $step->expected),
            'truthy' => (bool) $value,
            'exists' => $value !== null,
            default => false,
        };
        $selected = $run->artifact($matches ? $step->whenTrue : $step->whenFalse);

        return new ImmediateStepPlan(StepOutcome::artifactsProduced([
            new Artifact($step->outputKey, $selected->type, $selected->content, $selected->payload, [
                'branch' => $matches ? 'true' : 'false',
            ]),
        ]));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Branch is immediate and cannot reduce invocations.');
    }

    private static function read(mixed $value, string $path): mixed
    {
        if ($path === '' || $path === '.') {
            return $value;
        }
        foreach (explode('.', trim($path, '.')) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
