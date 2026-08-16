<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ApplicationStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class WorkflowRunProgressTest extends TestCase
{
    public function test_progress_reports_status_step_label_and_position(): void
    {
        $run = $this->startRun();

        $progress = $run->progress();
        self::assertSame('created', $progress->status->value);
        self::assertSame(1, $progress->current);
        self::assertSame(2, $progress->total);
        self::assertSame('load-claim', $progress->stepId);
        self::assertSame('Loading claim', $progress->label);

        $run->beginStep(StepId::fromString('load-claim'));

        $progress = $run->progress();
        self::assertSame(RunStatus::Running, $progress->status);
        self::assertSame(1, $progress->current);
        self::assertSame('load-claim', $progress->stepId);
    }

    public function test_progress_marks_terminal_runs_complete(): void
    {
        $run = $this->startRun();
        $run->beginStep(StepId::fromString('load-claim'));
        $run->completeStep(StepId::fromString('load-claim'), \Rick\Laravel\Domain\Run\StepOutcome::completion([]));
        $run->beginStep(StepId::fromString('output'));
        $run->completeStep(StepId::fromString('output'), \Rick\Laravel\Domain\Run\StepOutcome::completion([]));

        $progress = $run->progress();
        self::assertSame(RunStatus::Completed, $progress->status);
        self::assertSame(2, $progress->current);
        self::assertSame(2, $progress->total);
        self::assertNull($progress->stepId);
        self::assertNull($progress->label);
    }

    private function startRun(): WorkflowRun
    {
        $workflow = new CompiledWorkflow(
            'claim-decision',
            '1.0.0',
            [
                new ApplicationStep(StepId::fromString('load-claim'), 'App\\LoadClaim', label: 'Loading claim'),
                new OutputGlueStep(StepId::fromString('output'), 'decision'),
            ],
        );

        return WorkflowRun::start(
            RunId::fromString('run-progress-1'),
            $workflow,
            new RunInput(['claim_id' => 1]),
            60,
        );
    }
}
