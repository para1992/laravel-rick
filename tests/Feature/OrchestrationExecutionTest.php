<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use DateTimeImmutable;
use Rick\Laravel\Application\Compilation\ValueObject\WorkflowDefinition;
use Rick\Laravel\Application\Execution\Request\ScheduleRunRequest;
use Rick\Laravel\Application\Execution\Result\ScheduleRunResult;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition as DomainWorkflowDefinition;
use Rick\Laravel\Tests\TestCase;

final class OrchestrationExecutionTest extends TestCase
{
    public function test_orchestration_compiles_a_workflow_and_starts_its_run(): void
    {
        $definition = new WorkflowDefinition(new DomainWorkflowDefinition(
            'first-workflow',
            '1.0.0',
            [
                new ResolveStep(
                    StepId::fromString('001_resolve'),
                    'Write',
                    DefinitionOfDone::fromString('Return the completed result'),
                ),
            ],
        ));
        $request = new ScheduleRunRequest(
            RunId::fromString('run-1'),
            new RunInput(['subject' => 'Laravel']),
            60,
            new DateTimeImmutable('2026-07-26T12:00:00+00:00'),
        );

        $app = $this->app;
        self::assertNotNull($app);

        $result = $app
            ->make(Handler::class)
            ->handle(
                Parcel::fromArray([$definition, $request]),
            );

        $run = $result->get(ScheduleRunResult::class)->run;
        $snapshot = $run->snapshot();

        self::assertSame('run-1', $run->id()->toString());
        self::assertSame(RunStatus::Created, $snapshot->status);
        self::assertSame(0, $snapshot->version);
        self::assertSame('Laravel', $snapshot->input->string('subject'));
        self::assertSame(60, $snapshot->callLimit);
        self::assertSame(
            '2026-07-26T12:00:00+00:00',
            $snapshot->startedAt?->format(DATE_ATOM),
        );
    }
}
