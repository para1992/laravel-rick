<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use DateTimeImmutable;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Recovery\InvocationRecovery;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRun;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\ValueObject\CompiledWorkflow;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Infrastructure\Recovery\InvocationRecoveryRunner;
use Rick\Laravel\Tests\TestCase;

final class RecoveryBatchTest extends TestCase
{
    public function test_recovery_processes_a_bounded_batch_and_rechecks_current_state(): void
    {
        $runId = RunId::fromString('recovery-batch');
        $stepId = StepId::fromString('generate');
        $executionId = StepExecutionId::fromString('recovery-execution');
        $this->application()->make(RunRepositoryBase::class)->add(WorkflowRun::start(
            $runId,
            new CompiledWorkflow('recovery', '1.0.0', [
                new ResolveStep(
                    StepId::fromString('resolve'),
                    'Recover bounded invocations',
                    DefinitionOfDone::fromString('Every expired lease is visible'),
                ),
            ]),
            new RunInput([]),
            10,
        ));
        $invocations = [];
        foreach (range(1, 3) as $index) {
            $invocation = LlmInvocation::pending(
                InvocationId::fromString('expired-'.$index),
                $executionId,
                $runId,
                $stepId,
                $index - 1,
                new CompletionRequest(
                    [new Message('user', 'Recover safely')],
                    ResponseContract::Text,
                    'recovery_test',
                ),
            );
            $invocation->start(new DateTimeImmutable('-1 minute'));
            $invocations[] = $invocation;
        }
        $repository = $this->application()->make(ExecutionRepositoryBase::class);
        $repository->add(
            StepExecution::waiting($executionId, $runId, $stepId, 3),
            $invocations,
        );
        $runner = new InvocationRecoveryRunner(
            $this->application()->make(InvocationRecovery::class),
            $repository,
            $this->application()->make(ExecutionBackendBase::class),
            $this->application()->make(TransactionBase::class),
            $this->application()->make(ClockBase::class),
            $this->application()->make(EventOutboxBase::class),
            2,
        );

        self::assertSame(2, $runner->markExpired());
        self::assertSame(2, $this->countStatus($repository, $invocations, InvocationStatus::Indeterminate));
        self::assertSame(1, $this->countStatus($repository, $invocations, InvocationStatus::Running));

        self::assertSame(1, $runner->markExpired());
        self::assertSame(3, $this->countStatus($repository, $invocations, InvocationStatus::Indeterminate));
        self::assertSame(0, $runner->markExpired());
    }

    /** @param list<LlmInvocation> $invocations */
    private function countStatus(
        ExecutionRepositoryBase $repository,
        array $invocations,
        InvocationStatus $status,
    ): int {
        $count = 0;
        foreach ($invocations as $invocation) {
            if ($repository->getInvocation($invocation->id())->status() === $status) {
                $count++;
            }
        }

        return $count;
    }
}
