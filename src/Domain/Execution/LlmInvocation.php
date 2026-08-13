<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

use DateTimeImmutable;
use InvalidArgumentException;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class LlmInvocation
{
    private InvocationStatus $status = InvocationStatus::Pending;

    private int $attempts = 0;

    private int $version = 0;

    private ?CompletionResponse $response = null;

    private ?CompletionMetrics $metrics = null;

    private ?string $errorCode = null;

    private ?string $errorMessage = null;

    private ?DateTimeImmutable $leaseExpiresAt = null;

    private function __construct(
        private readonly InvocationId $id,
        private readonly StepExecutionId $executionId,
        private readonly RunId $runId,
        private readonly StepId $stepId,
        private readonly int $index,
        private readonly CompletionRequest $request,
        private readonly ?RunId $sourceRunId = null,
        private readonly ?InvocationId $sourceInvocationId = null,
    ) {
        if ($index < 0) {
            throw new InvalidArgumentException('Invocation index must be zero or greater.');
        }
    }

    public static function pending(
        InvocationId $id,
        StepExecutionId $executionId,
        RunId $runId,
        StepId $stepId,
        int $index,
        CompletionRequest $request,
    ): self {
        return new self($id, $executionId, $runId, $stepId, $index, $request);
    }

    public static function restore(
        InvocationId $id,
        StepExecutionId $executionId,
        RunId $runId,
        StepId $stepId,
        int $index,
        CompletionRequest $request,
        InvocationStatus $status,
        int $attempts,
        int $version,
        ?CompletionResponse $response,
        ?string $errorCode,
        ?string $errorMessage,
        ?DateTimeImmutable $leaseExpiresAt = null,
        ?CompletionMetrics $metrics = null,
        ?RunId $sourceRunId = null,
        ?InvocationId $sourceInvocationId = null,
    ): self {
        $invocation = new self(
            $id,
            $executionId,
            $runId,
            $stepId,
            $index,
            $request,
            $sourceRunId,
            $sourceInvocationId,
        );
        $invocation->status = $status;
        $invocation->attempts = $attempts;
        $invocation->version = $version;
        $invocation->response = $response;
        $invocation->errorCode = $errorCode;
        $invocation->errorMessage = $errorMessage;
        $invocation->leaseExpiresAt = $leaseExpiresAt;
        $invocation->metrics = $metrics ?? $response?->metrics;

        return $invocation;
    }

    public static function reused(
        InvocationId $id,
        StepExecutionId $executionId,
        RunId $runId,
        StepId $stepId,
        int $index,
        CompletionRequest $request,
        LlmInvocation $source,
    ): self {
        $response = $source->response
            ?? throw new InvalidArgumentException('A recovery reuse source must have a response.');
        if ($source->status !== InvocationStatus::Succeeded) {
            throw new InvalidArgumentException('Only a successful invocation response may be reused.');
        }
        $invocation = new self(
            $id,
            $executionId,
            $runId,
            $stepId,
            $index,
            $request,
            $source->runId,
            $source->id,
        );
        $invocation->status = InvocationStatus::Succeeded;
        $invocation->response = new CompletionResponse(
            $response->text,
            $response->structured,
            $response->provider,
            $response->model,
            array_merge($response->metadata, [
                'reused_from_run_id' => $source->runId->toString(),
                'reused_from_invocation_id' => $source->id->toString(),
            ]),
            metrics: null,
            diagnostic: $response->diagnostic,
        );

        return $invocation;
    }

    public static function unavailableFrom(
        InvocationId $id,
        StepExecutionId $executionId,
        RunId $runId,
        StepId $stepId,
        int $index,
        CompletionRequest $request,
        LlmInvocation $source,
    ): self {
        if (! in_array($source->status, [InvocationStatus::Failed, InvocationStatus::Pending], true)) {
            throw new InvalidArgumentException('A copied recovery failure must come from an unavailable invocation.');
        }
        if ($source->status === InvocationStatus::Pending && $source->attempts !== 0) {
            throw new InvalidArgumentException('An undispatched recovery source cannot contain attempts.');
        }
        $invocation = new self(
            $id,
            $executionId,
            $runId,
            $stepId,
            $index,
            $request,
            $source->runId,
            $source->id,
        );
        $invocation->status = InvocationStatus::Failed;
        $invocation->errorCode = $source->status === InvocationStatus::Failed
            ? $source->errorCode
            : 'recovery_source_undispatched';
        $invocation->errorMessage = $source->status === InvocationStatus::Failed
            ? $source->errorMessage
            : 'Source invocation was not dispatched before the parent run became terminal.';

        return $invocation;
    }

    public function start(?DateTimeImmutable $leaseExpiresAt = null): void
    {
        if ($this->status !== InvocationStatus::Pending) {
            throw new InvalidStateTransitionException('Only a pending invocation may start.');
        }

        $this->status = InvocationStatus::Running;
        $this->attempts++;
        $this->errorCode = null;
        $this->errorMessage = null;
        $this->leaseExpiresAt = $leaseExpiresAt;
        $this->version++;
    }

    public function succeed(CompletionResponse $response): void
    {
        if ($this->status !== InvocationStatus::Running) {
            throw new InvalidStateTransitionException('Only a running invocation may succeed.');
        }

        $this->response = $response;
        $this->metrics = $response->metrics;
        $this->status = InvocationStatus::Succeeded;
        $this->leaseExpiresAt = null;
        $this->version++;
    }

    public function release(string $errorCode, string $message): void
    {
        if ($this->status !== InvocationStatus::Running) {
            throw new InvalidStateTransitionException('Only a running invocation may be released for retry.');
        }

        $this->status = InvocationStatus::Pending;
        $this->errorCode = $errorCode;
        $this->errorMessage = $message;
        $this->leaseExpiresAt = null;
        $this->version++;
    }

    public function fail(string $errorCode, string $message): void
    {
        if (in_array($this->status, [
            InvocationStatus::Succeeded,
            InvocationStatus::Failed,
            InvocationStatus::Indeterminate,
        ], true)) {
            return;
        }

        $this->status = InvocationStatus::Failed;
        $this->errorCode = $errorCode;
        $this->errorMessage = $message;
        $this->leaseExpiresAt = null;
        $this->version++;
    }

    public function markIndeterminate(string $errorCode, string $message): void
    {
        if ($this->status !== InvocationStatus::Running) {
            throw new InvalidStateTransitionException('Only a running invocation may become indeterminate.');
        }

        $this->status = InvocationStatus::Indeterminate;
        $this->errorCode = $errorCode;
        $this->errorMessage = $message;
        $this->leaseExpiresAt = null;
        $this->version++;
    }

    public function retryIndeterminate(): void
    {
        if ($this->status !== InvocationStatus::Indeterminate) {
            throw new InvalidStateTransitionException('Only an indeterminate invocation may be retried manually.');
        }
        $this->status = InvocationStatus::Pending;
        $this->errorCode = 'manual_retry_authorized';
        $this->errorMessage = 'An operator reconciled the provider outcome and authorized a retry.';
        $this->version++;
    }

    public function failIndeterminate(string $message): void
    {
        if ($this->status !== InvocationStatus::Indeterminate) {
            throw new InvalidStateTransitionException('Only an indeterminate invocation may be failed manually.');
        }
        $this->status = InvocationStatus::Failed;
        $this->errorCode = 'manual_recovery_failed';
        $this->errorMessage = $message;
        $this->version++;
    }

    public function id(): InvocationId
    {
        return $this->id;
    }

    public function executionId(): StepExecutionId
    {
        return $this->executionId;
    }

    public function runId(): RunId
    {
        return $this->runId;
    }

    public function stepId(): StepId
    {
        return $this->stepId;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function request(): CompletionRequest
    {
        return $this->request;
    }

    public function status(): InvocationStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function response(): ?CompletionResponse
    {
        return $this->response;
    }

    public function recordMetrics(CompletionMetrics $metrics): void
    {
        if ($this->status !== InvocationStatus::Running) {
            throw new InvalidStateTransitionException('Only a running invocation may record provider metrics.');
        }

        $this->metrics = $metrics;
    }

    public function metrics(): ?CompletionMetrics
    {
        return $this->metrics;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function leaseExpiresAt(): ?DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    public function sourceRunId(): ?RunId
    {
        return $this->sourceRunId;
    }

    public function sourceInvocationId(): ?InvocationId
    {
        return $this->sourceInvocationId;
    }

    public function isReused(): bool
    {
        return $this->status === InvocationStatus::Succeeded
            && $this->sourceRunId !== null
            && $this->sourceInvocationId !== null
            && $this->attempts === 0;
    }
}
