<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Interaction;

use Rick\Laravel\Domain\Run\PendingInput;
use Rick\Laravel\Domain\Run\PendingInteraction;
use Rick\Laravel\Domain\Run\PendingReview;
use Rick\Laravel\Domain\Run\WorkflowRun;
use UnexpectedValueException;

final readonly class PendingInteractionProjection
{
    public function interaction(WorkflowRun $run): PendingInteraction
    {
        return PendingInteraction::resolve($this->review($run), $this->input($run));
    }

    public function review(WorkflowRun $run): PendingReview
    {
        $snapshot = $run->snapshot();

        return new PendingReview(
            $snapshot->currentCandidates === [] ? null : $run->runningStepId(),
            $snapshot->currentCandidates,
        );
    }

    public function input(WorkflowRun $run): PendingInput
    {
        $stepId = $run->runningStepId();
        $state = $stepId === null ? [] : $run->snapshot()->stepState($stepId->toString());
        $pending = is_array($state['pending_input'] ?? null)
            ? self::map($state['pending_input'])
            : [];
        $schema = $pending['schema'] ?? null;

        return new PendingInput(
            $pending === [] ? null : $stepId,
            is_string($pending['key'] ?? null) ? $pending['key'] : null,
            is_string($pending['prompt'] ?? null) ? $pending['prompt'] : null,
            is_array($schema) ? self::map($schema) : null,
        );
    }

    /** @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Pending input state must contain JSON objects.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
