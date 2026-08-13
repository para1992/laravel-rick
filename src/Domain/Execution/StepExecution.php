<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

use InvalidArgumentException;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class StepExecution
{
    private StepExecutionStatus $status = StepExecutionStatus::Waiting;

    private int $version = 0;

    private ?string $errorCode = null;

    private ?string $errorMessage = null;

    private InvocationCompletionPolicy $completionPolicy;

    private function __construct(
        private readonly StepExecutionId $id,
        private readonly RunId $runId,
        private readonly StepId $stepId,
        private int $expectedInvocations,
        private int $dispatchedInvocations = 0,
        ?InvocationCompletionPolicy $completionPolicy = null,
    ) {
        if ($expectedInvocations < 0) {
            throw new InvalidArgumentException('Expected invocation count cannot be negative.');
        }

        if ($dispatchedInvocations < 0 || $dispatchedInvocations > $expectedInvocations) {
            throw new InvalidArgumentException('Dispatched invocation count must fit the current batch.');
        }
        $this->completionPolicy = $completionPolicy ?? InvocationCompletionPolicy::allRequired();
        if ($expectedInvocations > 0) {
            $this->completionPolicy->required($expectedInvocations);
        }
    }

    public static function waiting(
        StepExecutionId $id,
        RunId $runId,
        StepId $stepId,
        int $expectedInvocations,
        ?InvocationCompletionPolicy $completionPolicy = null,
    ): self {
        if ($expectedInvocations < 1) {
            throw new InvalidArgumentException('A waiting invocation barrier requires at least one invocation.');
        }

        return new self(
            $id,
            $runId,
            $stepId,
            $expectedInvocations,
            completionPolicy: $completionPolicy ?? InvocationCompletionPolicy::allRequired(),
        );
    }

    public static function awaitingInput(
        StepExecutionId $id,
        RunId $runId,
        StepId $stepId,
    ): self {
        $execution = new self($id, $runId, $stepId, 0);
        $execution->status = StepExecutionStatus::AwaitingInput;

        return $execution;
    }

    public static function restore(
        StepExecutionId $id,
        RunId $runId,
        StepId $stepId,
        int $expectedInvocations,
        StepExecutionStatus $status,
        int $version,
        ?string $errorCode,
        ?string $errorMessage,
        int $dispatchedInvocations = 0,
        ?InvocationCompletionPolicy $completionPolicy = null,
    ): self {
        $execution = new self(
            $id,
            $runId,
            $stepId,
            $expectedInvocations,
            $dispatchedInvocations,
            $completionPolicy ?? InvocationCompletionPolicy::allRequired(),
        );
        $execution->status = $status;
        $execution->version = $version;
        $execution->errorCode = $errorCode;
        $execution->errorMessage = $errorMessage;

        return $execution;
    }

    public function beginReduction(): void
    {
        if ($this->status !== StepExecutionStatus::Waiting) {
            throw new InvalidStateTransitionException('Only a waiting step execution may begin reduction.');
        }

        $this->status = StepExecutionStatus::Reducing;
        $this->version++;
    }

    public function awaitInput(): void
    {
        if ($this->status !== StepExecutionStatus::Continuing) {
            throw new InvalidStateTransitionException('Only a continuing step execution may await external input.');
        }

        $this->expectedInvocations = 0;
        $this->status = StepExecutionStatus::AwaitingInput;
        $this->version++;
    }

    public function beginInputReduction(): void
    {
        if ($this->status !== StepExecutionStatus::AwaitingInput) {
            throw new InvalidStateTransitionException('Only a step awaiting input may reduce a manual selection.');
        }

        $this->status = StepExecutionStatus::Reducing;
        $this->version++;
    }

    public function complete(): void
    {
        if ($this->status !== StepExecutionStatus::Reducing) {
            throw new InvalidStateTransitionException('Only a reducing step execution may complete.');
        }

        $this->status = StepExecutionStatus::Completed;
        $this->version++;
    }

    public function completeContinuation(): void
    {
        if ($this->status !== StepExecutionStatus::Continuing) {
            throw new InvalidStateTransitionException(
                'Only a continuing step execution may complete without another invocation.',
            );
        }

        $this->expectedInvocations = 0;
        $this->status = StepExecutionStatus::Completed;
        $this->version++;
    }

    public function continueAfterReduction(): void
    {
        if ($this->status !== StepExecutionStatus::Reducing) {
            throw new InvalidStateTransitionException('Only a reducing step execution may continue.');
        }

        $this->status = StepExecutionStatus::Continuing;
        $this->version++;
    }

    public function beginNextBatch(int $expectedInvocations): void
    {
        if ($this->status !== StepExecutionStatus::Continuing) {
            throw new InvalidStateTransitionException(
                'Only a continuing step execution may begin its next batch.',
            );
        }

        if ($expectedInvocations < 1) {
            throw new InvalidArgumentException('A durable invocation batch requires at least one invocation.');
        }

        $this->expectedInvocations = $expectedInvocations;
        $this->dispatchedInvocations = 0;
        $this->status = StepExecutionStatus::Waiting;
        $this->version++;
    }

    public function markDispatched(int $count): void
    {
        if ($this->status !== StepExecutionStatus::Waiting) {
            throw new InvalidStateTransitionException(
                'Only a waiting step execution may dispatch invocations.',
            );
        }

        if ($count < 1 || $this->dispatchedInvocations + $count > $this->expectedInvocations) {
            throw new InvalidArgumentException('Dispatch window exceeds the current invocation batch.');
        }

        $this->dispatchedInvocations += $count;
        $this->version++;
    }

    public function fail(string $errorCode, string $message): void
    {
        if ($this->status === StepExecutionStatus::Completed) {
            throw new InvalidStateTransitionException('A completed step execution cannot fail.');
        }

        $this->status = StepExecutionStatus::Failed;
        $this->errorCode = $errorCode;
        $this->errorMessage = $message;
        $this->version++;
    }

    public function id(): StepExecutionId
    {
        return $this->id;
    }

    public function runId(): RunId
    {
        return $this->runId;
    }

    public function stepId(): StepId
    {
        return $this->stepId;
    }

    public function expectedInvocations(): int
    {
        return $this->expectedInvocations;
    }

    public function dispatchedInvocations(): int
    {
        return $this->dispatchedInvocations;
    }

    public function status(): StepExecutionStatus
    {
        return $this->status;
    }

    public function completionPolicy(): InvocationCompletionPolicy
    {
        return $this->completionPolicy;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
