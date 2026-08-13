<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

use DateTimeImmutable;
use InvalidArgumentException;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final class InvocationAttempt
{
    private InvocationAttemptStatus $status = InvocationAttemptStatus::Running;

    private ?ProviderIdentifiers $providerIdentifiers = null;

    private ?AttemptMetrics $metrics = null;

    private ?StructuredResponseDiagnostic $diagnostic = null;

    private ?ProviderRequestOutcome $outcome = null;

    private ?string $errorCode = null;

    private ?string $errorMessage = null;

    private ?string $httpStatusClass = null;

    private ?DateTimeImmutable $finishedAt = null;

    private function __construct(
        private readonly InvocationAttemptId $id,
        private readonly InvocationId $invocationId,
        private readonly RunId $runId,
        private readonly int $number,
        private readonly string $requestFingerprint,
        private readonly DateTimeImmutable $startedAt,
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException('Attempt number must be positive.');
        }
    }

    public static function start(
        InvocationAttemptId $id,
        InvocationId $invocationId,
        RunId $runId,
        int $number,
        string $requestFingerprint,
        DateTimeImmutable $startedAt,
    ): self {
        return new self($id, $invocationId, $runId, $number, $requestFingerprint, $startedAt);
    }

    public static function restore(
        InvocationAttemptId $id,
        InvocationId $invocationId,
        RunId $runId,
        int $number,
        string $requestFingerprint,
        InvocationAttemptStatus $status,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $finishedAt,
        ?string $providerRequestId,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $httpStatusClass = null,
        ?string $gatewayInvocationId = null,
        ?string $providerGenerationId = null,
        ProviderIdSource $providerIdSource = ProviderIdSource::Unavailable,
        ?AttemptMetrics $metrics = null,
        ?StructuredResponseDiagnostic $diagnostic = null,
        ?ProviderRequestOutcome $outcome = null,
    ): self {
        $attempt = new self($id, $invocationId, $runId, $number, $requestFingerprint, $startedAt);
        $attempt->status = $status;
        $attempt->finishedAt = $finishedAt;
        $attempt->providerIdentifiers = new ProviderIdentifiers(
            $gatewayInvocationId,
            $providerRequestId,
            $providerGenerationId,
            $providerRequestId === null && $providerGenerationId === null
                ? ProviderIdSource::Unavailable
                : ($providerIdSource === ProviderIdSource::Unavailable
                    ? ProviderIdSource::Sdk
                    : $providerIdSource),
        );
        $attempt->errorCode = $errorCode;
        $attempt->errorMessage = $errorMessage;
        $attempt->httpStatusClass = $httpStatusClass;
        $attempt->metrics = $metrics;
        $attempt->diagnostic = $diagnostic;
        $attempt->outcome = $outcome;

        return $attempt;
    }

    public function succeed(
        ProviderIdentifiers $identifiers,
        AttemptMetrics $metrics,
        DateTimeImmutable $finishedAt,
        ?StructuredResponseDiagnostic $diagnostic = null,
    ): void {
        $this->finish(InvocationAttemptStatus::Succeeded, $finishedAt);
        $this->providerIdentifiers = $identifiers;
        $this->metrics = $metrics;
        $this->diagnostic = $diagnostic;
        $this->outcome = ProviderRequestOutcome::ResponseReceived;
    }

    public function fail(
        string $code,
        string $message,
        DateTimeImmutable $finishedAt,
        ?ProviderIdentifiers $identifiers = null,
        ?string $httpStatusClass = null,
        ?AttemptMetrics $metrics = null,
        ?StructuredResponseDiagnostic $diagnostic = null,
        ?ProviderRequestOutcome $outcome = null,
    ): void {
        $this->finish(InvocationAttemptStatus::Failed, $finishedAt);
        $this->errorCode = $code;
        $this->errorMessage = $message;
        $this->providerIdentifiers = $identifiers;
        $this->httpStatusClass = $httpStatusClass;
        $this->metrics = $metrics;
        $this->diagnostic = $diagnostic;
        $this->outcome = $outcome;
    }

    public function markIndeterminate(
        string $code,
        string $message,
        DateTimeImmutable $finishedAt,
        ?ProviderIdentifiers $identifiers = null,
        ?string $httpStatusClass = null,
        ?AttemptMetrics $metrics = null,
        ?StructuredResponseDiagnostic $diagnostic = null,
    ): void {
        $this->finish(InvocationAttemptStatus::Indeterminate, $finishedAt);
        $this->errorCode = $code;
        $this->errorMessage = $message;
        $this->providerIdentifiers = $identifiers;
        $this->httpStatusClass = $httpStatusClass;
        $this->metrics = $metrics;
        $this->diagnostic = $diagnostic;
        $this->outcome = ProviderRequestOutcome::Indeterminate;
    }

    private function finish(InvocationAttemptStatus $status, DateTimeImmutable $finishedAt): void
    {
        if ($this->status !== InvocationAttemptStatus::Running) {
            throw new InvalidStateTransitionException('Only a running attempt may finish.');
        }
        $this->status = $status;
        $this->finishedAt = $finishedAt;
    }

    public function id(): InvocationAttemptId
    {
        return $this->id;
    }

    public function invocationId(): InvocationId
    {
        return $this->invocationId;
    }

    public function runId(): RunId
    {
        return $this->runId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function requestFingerprint(): string
    {
        return $this->requestFingerprint;
    }

    public function status(): InvocationAttemptStatus
    {
        return $this->status;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function providerRequestId(): ?string
    {
        return $this->providerIdentifiers?->providerRequestId;
    }

    public function providerIdentifiers(): ?ProviderIdentifiers
    {
        return $this->providerIdentifiers;
    }

    public function metrics(): ?AttemptMetrics
    {
        return $this->metrics;
    }

    public function diagnostic(): ?StructuredResponseDiagnostic
    {
        return $this->diagnostic;
    }

    public function outcome(): ?ProviderRequestOutcome
    {
        return $this->outcome;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function httpStatusClass(): ?string
    {
        return $this->httpStatusClass;
    }
}
