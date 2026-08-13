<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Exception\QualityGateFailedException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\LlmOperationBase;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\LlmOperationRegistry;
use Rick\Laravel\Application\Execution\Support\Llm\Operation\OperationContext;
use Rick\Laravel\Application\Execution\Support\Quality\RepairPolicyRegistry;
use Rick\Laravel\Application\Execution\Support\Quality\Request\RepairDecisionRequest;
use Rick\Laravel\Application\Execution\Support\Quality\Result\QualityReport;
use Rick\Laravel\Application\Execution\Support\Quality\Result\RepairDecision;
use Rick\Laravel\Application\Execution\Support\Quality\RuleSetRegistry;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\QualityGateStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class QualityGateStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(
        private RuleSetRegistry $rules,
        private RepairPolicyRegistry $policies,
        private LlmOperationRegistry $operations,
    ) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === 'quality_gate';
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        $step = $this->step($step);
        $state = $run->stepState($step->id()->toString());
        $repairs = is_int($state['repairs_used'] ?? null) ? $state['repairs_used'] : 0;
        $artifact = $this->currentArtifact($step, $run, $repairs);
        $report = $this->rules->get($step->ruleSetId)->evaluate($artifact, $run);
        $decision = $this->decision($step, $report, $repairs);

        if ($decision === RepairDecision::Fail) {
            throw new QualityGateFailedException($report);
        }
        if ($decision === RepairDecision::Repair) {
            $operation = $this->repairOperation($step);

            return new InvocationStepPlan($operation->requests(new OperationContext(
                $run,
                ['artifact' => $artifact],
                $step->resolvedOutputKey(),
                [
                    'quality_report' => $report->toArray(),
                    'repair_number' => $repairs + 1,
                    'maximum_repairs' => $step->maxRepairs,
                ],
                $repairs + 1,
            )));
        }

        return new ImmediateStepPlan($this->outcome($step, $artifact, $report, $repairs));
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        $responses = InvocationResponses::successful($outcomes);
        $step = $this->step($step);
        $state = $run->stepState($step->id()->toString());
        $repairsUsed = is_int($state['repairs_used'] ?? null) ? $state['repairs_used'] : 0;
        $original = $this->currentArtifact($step, $run, $repairsUsed);
        $before = $this->rules->get($step->ruleSetId)->evaluate($original, $run);
        $produced = $this->repairOperation($step)
            ->reduce(new OperationContext(
                $run,
                ['artifact' => $original],
                $step->resolvedOutputKey(),
                [
                    'quality_report' => $before->toArray(),
                    'repair_number' => $repairsUsed + 1,
                    'maximum_repairs' => $step->maxRepairs,
                ],
                $repairsUsed + 1,
            ), $responses)
            ->artifacts[0];
        $repairsUsed++;
        $repaired = new Artifact(
            $step->resolvedOutputKey(),
            $original->type,
            $produced->content,
            $produced->payload,
            array_replace($original->metadata, $produced->metadata, [
                'repaired_by' => $step->repairOperationId,
                'quality_repairs' => $repairsUsed,
            ]),
        );
        $report = $this->rules->get($step->ruleSetId)->evaluate($repaired, $run);
        $decision = $this->decision($step, $report, $repairsUsed);
        if ($decision === RepairDecision::Accept) {
            return $this->outcome($step, $repaired, $report, $repairsUsed);
        }

        $reports = is_array($state['reports'] ?? null) ? $state['reports'] : [];
        $reports[] = $report->toArray();
        if ($decision === RepairDecision::Fail) {
            return StepOutcome::continuation(
                [
                    'phase' => 'failed',
                    'repairs_used' => $repairsUsed,
                    'reports' => $reports,
                ],
                metadata: ['quality_gate' => 'failed', 'repairs_used' => $repairsUsed],
                artifacts: [
                    $repaired,
                    $this->reportArtifact($step, $report),
                ],
            );
        }

        return StepOutcome::continuation(
            [
                'phase' => 'repair',
                'repairs_used' => $repairsUsed,
                'reports' => $reports,
            ],
            metadata: ['quality_gate' => 'repair', 'repairs_used' => $repairsUsed],
            artifacts: [
                $repaired,
                $this->reportArtifact($step, $report),
            ],
        );
    }

    private function outcome(
        QualityGateStep $step,
        Artifact $artifact,
        QualityReport $report,
        int $repairsUsed,
    ): StepOutcome {
        $output = new Artifact(
            $step->resolvedOutputKey(),
            $artifact->type,
            $artifact->content,
            $artifact->payload,
            array_replace($artifact->metadata, ['quality_repairs' => $repairsUsed]),
        );

        return StepOutcome::completion(
            [
                'phase' => 'passed',
                'repairs_used' => $repairsUsed,
                'reports' => [$report->toArray()],
            ],
            metadata: ['quality_gate' => 'passed', 'repairs_used' => $repairsUsed],
            artifacts: [
                $output,
                $this->reportArtifact($step, $report),
            ],
        );
    }

    private function reportArtifact(
        QualityGateStep $step,
        QualityReport $report,
    ): Artifact {
        $reportData = $report->toArray();

        return new Artifact(
            $step->resolvedOutputKey().'.quality',
            ArtifactType::fromString('quality_report'),
            json_encode($reportData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $reportData,
        );
    }

    private function decision(
        QualityGateStep $step,
        QualityReport $report,
        int $repairsUsed,
    ): RepairDecision {
        return $this->policies->get($step->repairPolicyId)->decide(new RepairDecisionRequest(
            $report,
            $repairsUsed,
            $step->maxRepairs,
            $step->repairOperationId !== null,
        ));
    }

    private function currentArtifact(
        QualityGateStep $step,
        WorkflowRunSnapshot $run,
        int $repairsUsed,
    ): Artifact {
        return $repairsUsed > 0 && $run->hasArtifact($step->resolvedOutputKey())
            ? $run->artifact($step->resolvedOutputKey())
            : $run->artifact($step->artifactKey);
    }

    private function repairOperation(
        QualityGateStep $step,
    ): LlmOperationBase {
        return $this->operations->get(
            $step->repairOperationId
                ?? throw new LogicException('Quality repair has no configured operation.'),
            $step->repairOperationVersion,
        );
    }

    private function step(StepBase $step): QualityGateStep
    {
        return $step instanceof QualityGateStep
            ? $step
            : throw new LogicException('Quality-gate strategy received an incompatible step.');
    }
}
