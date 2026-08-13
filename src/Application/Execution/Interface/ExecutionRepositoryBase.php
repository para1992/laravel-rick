<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Interface;

use DateTimeImmutable;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\StepExecution;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

interface ExecutionRepositoryBase
{
    /** @param list<LlmInvocation> $invocations */
    public function add(StepExecution $execution, array $invocations): void;

    public function findForStep(RunId $runId, StepId $stepId): ?StepExecution;

    public function getInvocation(InvocationId $id): LlmInvocation;

    public function addAttempt(InvocationAttempt $attempt): void;

    public function saveAttempt(InvocationAttempt $attempt): void;

    public function latestAttemptFor(InvocationId $id): ?InvocationAttempt;

    /** @return list<InvocationAttempt> */
    public function attemptsForRun(RunId $runId): array;

    /** @return list<LlmInvocation> */
    public function staleRunning(DateTimeImmutable $expiredBefore, int $limit): array;

    public function saveExecution(StepExecution $execution, int $expectedVersion): void;

    public function saveInvocation(LlmInvocation $invocation, int $expectedVersion): void;

    /** @return list<LlmInvocation> */
    public function invocationsFor(StepExecutionId $executionId): array;

    /** @return list<LlmInvocation> */
    public function invocationsForRun(RunId $runId): array;
}
