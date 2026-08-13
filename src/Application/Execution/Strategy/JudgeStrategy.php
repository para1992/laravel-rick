<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Domain\Execution\Interface\CandidateSelectionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\AwaitingCandidateSelectionPlan;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\JudgeStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final class JudgeStrategy implements CandidateSelectionBase, StepStrategyBase
{
    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::judge()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof JudgeStep || $run->currentCandidates === []) {
            throw new LogicException('Judge requires current candidates.');
        }

        return new AwaitingCandidateSelectionPlan(['scope' => 'workflow_candidate']);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $responses): StepOutcome
    {
        throw new LogicException('Judge is reduced through candidate selection, not LLM responses.');
    }

    public function select(
        StepBase $step,
        WorkflowRunSnapshot $run,
        CandidateId $candidateId,
    ): StepOutcome {
        if (! $step instanceof JudgeStep) {
            throw new LogicException('Judge strategy received an incompatible step.');
        }

        foreach ($run->currentCandidates as $candidate) {
            if ($candidate->id->toString() === $candidateId->toString()) {
                return StepOutcome::judged(new CandidateDecision(
                    $step->id(),
                    $candidateId,
                    null,
                    'Selected through external review.',
                    'manual',
                ));
            }
        }

        throw new LogicException(sprintf(
            'Candidate [%s] is not available for selection.',
            $candidateId->toString(),
        ));
    }
}
