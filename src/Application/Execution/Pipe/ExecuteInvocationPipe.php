<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use DateInterval;
use LogicException;
use Rick\Laravel\Application\Execution\Exception\ConcurrentExecutionModificationException;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Exception\StructuredResponseException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Request\ExecuteInvocationRequest;
use Rick\Laravel\Application\Execution\Result\ExecuteInvocationResult;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Guard\ResourceBudgetGuard;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\ProviderRouteResolverBase;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicyRegistry;
use Rick\Laravel\Application\Execution\Support\Schema\CompletionResponseValidator;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Event\UsageRecorded;
use Rick\Laravel\Domain\Exception\CallBudgetExceededException;
use Rick\Laravel\Domain\Exception\ResourceBudgetExceededException;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\ValueObject\Parcel;
use Throwable;

final readonly class ExecuteInvocationPipe implements PipeBase
{
    public function __construct(
        private ExecutionRepositoryBase $executions,
        private RunRepositoryBase $runs,
        private TransactionBase $transactions,
        private ClockBase $clock,
        private GatewayBase $llm,
        private EventOutboxBase $events,
        private ExecutionBackendBase $backend,
        private ModelPolicyRegistry $models,
        private ProviderRouteResolverBase $routes,
        private ResourceBudgetGuard $budgets,
        private DomainEventRecorder $domainEvents,
        private IdGeneratorBase $ids,
        private CompletionResponseValidator $responses,
        private ResponseSchemaResolver $responseSchemas,
        private int $leaseSeconds = 300,
        private int $maxSafeAttempts = 3,
        private int $structuredResponseAttempts = 1,
        private string $structuredResponseStrategy = 'same_route_then_fallback',
    ) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(ExecuteInvocationRequest::class)) {
            return $next($parcel);
        }

        $request = $parcel->get(ExecuteInvocationRequest::class);
        $this->execute($request->invocationId, true);

        return $next($parcel->put(new ExecuteInvocationResult($request->invocationId)));
    }

    public function execute(InvocationId $invocationId, bool $recordHandoff = false): void
    {
        $claimed = $this->transactions->run(function () use ($invocationId): ?array {
            $invocation = $this->executions->getInvocation($invocationId);
            if (in_array($invocation->status(), [
                InvocationStatus::Succeeded,
                InvocationStatus::Failed,
                InvocationStatus::Indeterminate,
            ], true)) {
                return null;
            }
            $version = $invocation->version();
            if ($invocation->status() === InvocationStatus::Running) {
                if ($invocation->leaseExpiresAt() !== null && $invocation->leaseExpiresAt() > $this->clock->now()) {
                    return null;
                }
                $attempt = $this->executions->latestAttemptFor($invocationId);
                $message = 'The provider outcome is unknown because the invocation lease expired.';
                $invocation->markIndeterminate('invocation_lease_expired', $message);
                $this->executions->saveInvocation($invocation, $version);
                if ($attempt !== null && $attempt->status()->value === 'running') {
                    $attempt->markIndeterminate('invocation_lease_expired', $message, $this->clock->now());
                    $this->executions->saveAttempt($attempt);
                }

                return null;
            }
            $previousErrorCode = $invocation->errorCode();
            $invocation->start($this->clock->now()->add(new DateInterval('PT'.$this->leaseSeconds.'S')));
            $this->executions->saveInvocation($invocation, $version);
            $attempt = InvocationAttempt::start(
                InvocationAttemptId::fromString($this->ids->generate()),
                $invocation->id(),
                $invocation->runId(),
                $invocation->attempts(),
                $this->fingerprint($invocation),
                $this->clock->now(),
            );
            $this->executions->addAttempt($attempt);

            return [$invocation, $attempt, $previousErrorCode];
        });
        if ($claimed === null) {
            return;
        }
        [$invocation, $attempt, $previousErrorCode] = $claimed;

        $request = $invocation->request();
        try {
            $request = $this->routed($request, $invocation->attempts(), $previousErrorCode);
        } catch (Throwable $error) {
            $failure = new ProviderRequestException(
                'provider_request_preflight_failed',
                'The provider request failed before transport accepted it.',
                false,
                ProviderRequestOutcome::NotAccepted,
                previous: $error,
            );
            [$retry, $decision] = $this->recordFailure(
                $invocationId,
                $attempt,
                $request,
                $failure,
                $recordHandoff,
            );
            $this->correlate($failure, $invocation, $attempt, $request, $decision);
            self::report($failure);
            if ($retry) {
                $this->execute($invocationId, $recordHandoff);
            }

            return;
        }

        try {
            $response = $this->llm->complete($request);
        } catch (Throwable $error) {
            $failure = $error instanceof ProviderRequestException
                ? $error
                : new ProviderRequestException(
                    'provider_outcome_indeterminate',
                    'The provider request outcome is unknown; operator reconciliation is required.',
                    false,
                    ProviderRequestOutcome::Indeterminate,
                    previous: $error,
                );
            [$retry, $decision] = $this->recordFailure(
                $invocationId,
                $attempt,
                $request,
                $failure,
                $recordHandoff,
            );
            $this->correlate($failure, $invocation, $attempt, $request, $decision);
            self::report($failure);
            if ($retry) {
                $this->execute($invocationId, $recordHandoff);
            }

            return;
        }

        try {
            $diagnostic = $this->responses->assert($request, $response);
            if ($diagnostic !== null) {
                $response = $response->withDiagnostic($diagnostic);
            }
        } catch (Throwable $error) {
            $identifiers = ProviderIdentifiers::fromMetadata($response->metadata);
            $failure = new ProviderRequestException(
                'provider_response_invalid',
                'The paid provider response did not satisfy its declared schema.',
                false,
                ProviderRequestOutcome::ResponseReceived,
                self::requestId($response->metadata),
                $response->metrics,
                $error,
                identifiers: $identifiers,
                diagnostic: $error instanceof StructuredResponseException
                    ? $error->diagnostic
                    : $response->diagnostic,
                provider: $response->provider,
                model: $response->model,
                resolvedRoute: self::metadataString($response->metadata, 'resolved_route')
                    ?? $response->provider.':'.$response->model,
                modelTier: $request->modelTier,
            );
            [$retry, $decision] = $this->recordFailure(
                $invocationId,
                $attempt,
                $request,
                $failure,
                $recordHandoff,
            );
            $this->correlate($failure, $invocation, $attempt, $request, $decision);
            self::report($failure);
            if ($retry) {
                $this->execute($invocationId, $recordHandoff);
            }

            return;
        }

        $this->transactions->run(function () use (
            $invocationId,
            $attempt,
            $response,
            $request,
            $recordHandoff,
        ): void {
            $stored = $this->executions->getInvocation($invocationId);
            if ($stored->status() !== InvocationStatus::Running) {
                return;
            }
            $storedAttempt = $this->executions->latestAttemptFor($invocationId);
            if (
                $storedAttempt === null
                || $storedAttempt->id()->toString() !== $attempt->id()->toString()
            ) {
                return;
            }
            $version = $stored->version();
            $stored->succeed($response);
            $this->executions->saveInvocation($stored, $version);
            $storedAttempt->succeed(
                ProviderIdentifiers::fromMetadata($response->metadata),
                self::attemptMetrics($request, $response),
                $this->clock->now(),
                $response->diagnostic,
            );
            $this->executions->saveAttempt($storedAttempt);
            if ($response->metrics !== null) {
                $metrics = $response->metrics;
                $event = new UsageRecorded(
                    $stored->runId(),
                    $stored->stepId(),
                    $stored->id(),
                    $stored->request()->purpose,
                    $stored->request()->modelTier,
                    $response->provider,
                    $response->model,
                    $metrics->tokens,
                    $metrics->cost,
                    $metrics->latencyMilliseconds,
                    $metrics->providerRequests,
                    $metrics->usageComplete,
                    $this->clock->now(),
                );
                $this->events->record($event);
            }
            if ($recordHandoff) {
                $this->backend->continue($stored->runId(), $stored->version(), $stored->id());
            }
        });
    }

    public function fail(
        InvocationId $invocationId,
        string $code,
        string $message,
        bool $recordHandoff = false,
    ): void {
        $this->transactions->run(function () use (
            $invocationId,
            $code,
            $message,
            $recordHandoff,
        ): void {
            $invocation = $this->executions->getInvocation($invocationId);
            $version = $invocation->version();
            $invocation->fail($code, $message);
            $this->executions->saveInvocation($invocation, $version);
            if ($recordHandoff) {
                $this->backend->continue($invocation->runId(), $invocation->version(), $invocation->id());
            }
        });
    }

    /** @return array{bool, string} */
    private function recordFailure(
        InvocationId $invocationId,
        InvocationAttempt $attempt,
        CompletionRequest $request,
        ProviderRequestException $error,
        bool $recordHandoff,
    ): array {
        for ($transition = 1; $transition <= 5; $transition++) {
            try {
                return $this->transactions->run(function () use (
                    $invocationId,
                    $attempt,
                    $request,
                    $error,
                    $recordHandoff,
                ): array {
                    $stored = $this->executions->getInvocation($invocationId);
                    if ($stored->status() !== InvocationStatus::Running) {
                        return [false, 'stale_attempt_ignored'];
                    }
                    $storedAttempt = $this->executions->latestAttemptFor($invocationId);
                    if (
                        $storedAttempt === null
                        || $storedAttempt->id()->toString() !== $attempt->id()->toString()
                    ) {
                        return [false, 'stale_attempt_ignored'];
                    }
                    $version = $stored->version();
                    $attemptMetrics = self::failureMetrics($request, $error);
                    [$retryRequest, $decision] = $this->retryPlan($stored, $error);
                    if ($retryRequest !== null) {
                        try {
                            $run = $this->runs->get($stored->runId());
                            $this->budgets->assertCanDispatch(
                                $run->snapshot(),
                                [$retryRequest],
                                $attemptMetrics,
                                $error->outcome,
                            );
                            $runVersion = $run->version();
                            $run->reserveCall($retryRequest->purpose);
                            $this->runs->save($run, $runVersion);
                            $this->domainEvents->record($run);
                        } catch (CallBudgetExceededException|ResourceBudgetExceededException) {
                            $retryRequest = null;
                            $decision = 'retry_budget_rejected';
                        }
                    }
                    $diagnostic = $error->diagnostic?->withRetryDecision($decision);
                    if ($error->outcome === ProviderRequestOutcome::Indeterminate) {
                        $stored->markIndeterminate($error->safeCode, $error->safeMessage);
                        $storedAttempt->markIndeterminate(
                            $error->safeCode,
                            $error->safeMessage,
                            $this->clock->now(),
                            self::identifiers($error),
                            $error->httpStatusClass,
                            $attemptMetrics,
                            $diagnostic,
                        );
                    } elseif ($retryRequest === null) {
                        if ($error->metrics !== null) {
                            $stored->recordMetrics($error->metrics);
                        }
                        $stored->fail($error->safeCode, $error->safeMessage);
                        $storedAttempt->fail(
                            $error->safeCode,
                            $error->safeMessage,
                            $this->clock->now(),
                            self::identifiers($error),
                            $error->httpStatusClass,
                            $attemptMetrics,
                            $diagnostic,
                            $error->outcome,
                        );
                    } else {
                        $stored->release($error->safeCode, $error->safeMessage);
                        $storedAttempt->fail(
                            $error->safeCode,
                            $error->safeMessage,
                            $this->clock->now(),
                            self::identifiers($error),
                            $error->httpStatusClass,
                            $attemptMetrics,
                            $diagnostic,
                            $error->outcome,
                        );
                    }
                    $this->executions->saveInvocation($stored, $version);
                    $this->executions->saveAttempt($storedAttempt);
                    if ($attemptMetrics !== null) {
                        $this->events->record(new UsageRecorded(
                            $stored->runId(),
                            $stored->stepId(),
                            $stored->id(),
                            $stored->request()->purpose,
                            $attemptMetrics->modelTier,
                            $attemptMetrics->provider,
                            $attemptMetrics->model,
                            $attemptMetrics->tokens,
                            $attemptMetrics->cost,
                            $attemptMetrics->latencyMilliseconds,
                            $attemptMetrics->providerRequests,
                            $attemptMetrics->usageComplete,
                            $this->clock->now(),
                        ));
                    }
                    $retry = $retryRequest !== null
                        && $stored->status() === InvocationStatus::Pending;
                    if ($recordHandoff && ! $retry) {
                        $this->backend->continue($stored->runId(), $stored->version(), $stored->id());
                    }

                    return [$retry, $decision];
                });
            } catch (ConcurrentExecutionModificationException $failure) {
                if ($transition === 5) {
                    throw $failure;
                }
            }
        }

        throw new LogicException('Invocation failure transition retry loop was exhausted.');
    }

    private function fingerprint(LlmInvocation $invocation): string
    {
        $request = $invocation->request();
        $payload = [
            'messages' => array_map(
                static fn ($message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                ],
                $request->messages,
            ),
            'contract' => $request->responseContract->value,
            'purpose' => $request->purpose,
            'model_tier' => $request->modelTier,
            'options' => $request->options,
            'schema' => $request->responseSchema,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $metadata */
    private static function requestId(array $metadata): ?string
    {
        $value = $metadata['provider_request_id'] ?? $metadata['request_id'] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || preg_match('//u', $value) !== 1) {
            return null;
        }
        $count = preg_match_all('/./us', $value, $characters);

        return is_int($count) && $count <= 128 ? $value : null;
    }

    private function correlate(
        ProviderRequestException $error,
        LlmInvocation $invocation,
        InvocationAttempt $attempt,
        CompletionRequest $request,
        string $retryDecision,
    ): void {
        $error->correlate([
            'run_id' => $invocation->runId()->toString(),
            'step_id' => $invocation->stepId()->toString(),
            'step_execution_id' => $invocation->executionId()->toString(),
            'invocation_id' => $invocation->id()->toString(),
            'invocation_index' => $invocation->index(),
            'candidate_number' => $invocation->index() + 1,
            'attempt_id' => $attempt->id()->toString(),
            'attempt_number' => $attempt->number(),
            'contract' => $request->responseContract->value,
            'schema_fingerprint' => $request->responseContract->value === 'text'
                ? null
                : $this->responseSchemas->fingerprint($request),
            'retry_decision' => $retryDecision,
        ]);
    }

    /** @return array{?CompletionRequest, string} */
    private function retryPlan(
        LlmInvocation $invocation,
        ProviderRequestException $error,
    ): array {
        if ($error->outcome === ProviderRequestOutcome::Indeterminate) {
            return [null, 'manual_reconciliation_required'];
        }

        if (
            $error->safeCode === 'provider_response_invalid'
            && $error->outcome === ProviderRequestOutcome::ResponseReceived
        ) {
            $attemptLimit = $invocation->request()->structuredResponseAttempts
                ?? $this->structuredResponseAttempts;
            if ($invocation->attempts() >= $attemptLimit) {
                return [null, 'structured_retry_exhausted'];
            }
            if ($this->structuredResponseStrategy !== 'same_route_then_fallback') {
                return [null, 'structured_retry_disabled'];
            }
            if (! $this->models->has($invocation->request()->modelTier)) {
                return [null, 'structured_fallback_unavailable'];
            }
            $policy = $this->models->get($invocation->request()->modelTier);
            if ($invocation->attempts() === 1) {
                return [
                    $invocation->request()->routed($policy->tier, $policy->options),
                    'structured_retry_same_route_scheduled',
                ];
            }
            $fallback = $this->structuredFallbackTier(
                $policy->tiers(),
                $invocation->attempts() + 1,
            );
            if ($fallback === null) {
                return [null, 'structured_fallback_unavailable'];
            }
            $routed = $invocation->request()->routed($fallback, $policy->options);

            return [$routed, 'structured_retry_scheduled'];
        }

        if (! $error->retryable || $invocation->attempts() >= $this->maxSafeAttempts) {
            return [null, $error->retryable ? 'safe_retry_exhausted' : 'retry_not_allowed'];
        }

        return [
            $this->routed(
                $invocation->request(),
                $invocation->attempts() + 1,
                $error->safeCode,
            ),
            'safe_retry_scheduled',
        ];
    }

    private function routed(
        CompletionRequest $request,
        int $attempt,
        ?string $previousErrorCode,
    ): CompletionRequest {
        if (! $this->models->has($request->modelTier)) {
            return $request;
        }
        $policy = $this->models->get($request->modelTier);
        $tier = $previousErrorCode === 'provider_response_invalid' && $attempt > 2
            ? $this->structuredFallbackTier($policy->tiers(), $attempt)
            : ($previousErrorCode === 'provider_response_invalid'
                ? $policy->tier
                : $policy->tierForAttempt($attempt));

        return $request->routed($tier ?? $request->modelTier, $policy->options);
    }

    /** @param non-empty-list<string> $tiers */
    private function structuredFallbackTier(array $tiers, int $attempt): ?string
    {
        $primary = array_shift($tiers);
        $primaryIdentity = $this->routes->identity($primary);
        $fallbacks = [];
        $seen = [$primaryIdentity => true];
        foreach ($tiers as $tier) {
            $identity = $this->routes->identity($tier);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $fallbacks[] = $tier;
        }

        return $fallbacks[$attempt - 3] ?? null;
    }

    private static function attemptMetrics(
        CompletionRequest $request,
        CompletionResponse $response,
    ): AttemptMetrics {
        $metrics = $response->metrics;
        $diagnostic = $response->diagnostic;

        return new AttemptMetrics(
            $response->provider,
            $response->model,
            self::metadataString($response->metadata, 'resolved_route')
                ?? $response->provider.':'.$response->model,
            $request->modelTier,
            $metrics === null ? TokenUsage::zero() : $metrics->tokens,
            $metrics?->cost,
            $metrics?->latencyMilliseconds,
            $metrics === null ? 1 : $metrics->providerRequests,
            $metrics !== null && $metrics->usagePresent,
            $metrics !== null && $metrics->usageComplete,
            self::promptCharacters($request),
            $diagnostic === null ? strlen($response->text) : $diagnostic->responseBytes,
        );
    }

    private static function failureMetrics(
        CompletionRequest $request,
        ProviderRequestException $error,
    ): ?AttemptMetrics {
        $metrics = $error->metrics;
        if ($metrics === null) {
            if ($error->outcome === ProviderRequestOutcome::NotAccepted) {
                return null;
            }
            $metrics = new CompletionMetrics(
                TokenUsage::zero(),
                null,
                providerRequests: 1,
                usageComplete: false,
                usagePresent: false,
            );
        }
        $provider = $error->provider ?? 'unknown';
        $model = $error->model ?? 'unknown';

        return new AttemptMetrics(
            $provider,
            $model,
            $error->resolvedRoute ?? $provider.':'.$model,
            $error->modelTier ?? $request->modelTier,
            $metrics->tokens,
            $metrics->cost,
            $metrics->latencyMilliseconds,
            $metrics->providerRequests,
            $metrics->usagePresent,
            $metrics->usageComplete,
            self::promptCharacters($request),
            $error->diagnostic === null ? 0 : $error->diagnostic->responseBytes,
        );
    }

    private static function identifiers(ProviderRequestException $error): ProviderIdentifiers
    {
        if ($error->identifiers !== null) {
            return $error->identifiers;
        }
        if ($error->requestId !== null) {
            return new ProviderIdentifiers(
                null,
                $error->requestId,
                null,
                ProviderIdSource::Header,
            );
        }

        return ProviderIdentifiers::unavailable();
    }

    private static function promptCharacters(CompletionRequest $request): int
    {
        return array_sum(array_map(
            static fn ($message): int => strlen($message->content),
            $request->messages,
        ));
    }

    /** @param array<string, mixed> $metadata */
    private static function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function report(Throwable $error): void
    {
        try {
            report($error);
        } catch (Throwable) {
        }
    }
}
