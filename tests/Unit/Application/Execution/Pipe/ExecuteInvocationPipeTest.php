<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Pipe;

use DateTimeImmutable;
use ReflectionMethod;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Interface\ExecutionBackendBase;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Interface\RunRepositoryBase;
use Rick\Laravel\Application\Execution\Pipe\ExecuteInvocationPipe;
use Rick\Laravel\Application\Execution\Support\Event\DomainEventRecorder;
use Rick\Laravel\Application\Execution\Support\Guard\ResourceBudgetGuard;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\ProviderRouteResolverBase;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicy;
use Rick\Laravel\Application\Execution\Support\Llm\ModelPolicyRegistry;
use Rick\Laravel\Application\Execution\Support\Schema\CompletionResponseValidator;
use Rick\Laravel\Application\Execution\Support\Schema\ResponseSchemaResolver;
use Rick\Laravel\Application\Interface\ClockBase;
use Rick\Laravel\Application\Interface\EventOutboxBase;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Application\Interface\TransactionBase;
use Rick\Laravel\Domain\Execution\InvocationAttempt;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\LlmInvocation;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StepExecutionId;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Llm\ValueObject\Message;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\AttemptMetrics;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Tests\TestCase;

final class ExecuteInvocationPipeTest extends TestCase
{
    public function test_request_fingerprint_covers_every_transport_input_exactly(): void
    {
        $request = new CompletionRequest(
            [new Message('system', 'Zażółć'), new Message('user', 'path / one')],
            ResponseContract::Json,
            'exact-purpose',
            'policy',
            ['temperature' => 0.25, 'nested' => ['slash' => '/']],
            responseSchema: [
                'type' => 'object',
                'properties' => ['answer' => ['type' => 'string']],
                'required' => ['answer'],
                'additionalProperties' => false,
            ],
        );
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => 'Zażółć'],
                ['role' => 'user', 'content' => 'path / one'],
            ],
            'contract' => 'json',
            'purpose' => 'exact-purpose',
            'model_tier' => 'policy',
            'options' => ['temperature' => 0.25, 'nested' => ['slash' => '/']],
            'schema' => $request->responseSchema,
        ];
        $expected = hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        self::assertSame(
            $expected,
            $this->invokePrivate($this->pipe(), 'fingerprint', [$this->invocation($request)]),
        );
    }

    public function test_provider_request_id_is_trimmed_bounded_and_prefers_canonical_key(): void
    {
        $pipe = $this->pipe();

        self::assertSame('canonical', $this->invokePrivate($pipe, 'requestId', [[
            'provider_request_id' => '  canonical  ',
            'request_id' => 'fallback',
        ]]));
        self::assertSame('fallback', $this->invokePrivate($pipe, 'requestId', [[
            'request_id' => 'fallback',
        ]]));
        self::assertSame(str_repeat('ą', 128), $this->invokePrivate($pipe, 'requestId', [[
            'request_id' => str_repeat('ą', 128),
        ]]));
        self::assertNull($this->invokePrivate($pipe, 'requestId', [['request_id' => str_repeat('a', 129)]]));
        self::assertNull($this->invokePrivate($pipe, 'requestId', [['request_id' => '   ']]));
        self::assertNull($this->invokePrivate($pipe, 'requestId', [['request_id' => "\xFF"]]));
        self::assertNull($this->invokePrivate($pipe, 'requestId', [['request_id' => 17]]));
        self::assertNull($this->invokePrivate($pipe, 'requestId', [[]]));
    }

    public function test_success_attempt_metrics_preserve_exact_route_usage_and_character_counts(): void
    {
        $request = new CompletionRequest(
            [new Message('system', 'Żółć'), new Message('user', 'abc')],
            ResponseContract::Candidate,
            'candidate',
            'policy',
        );
        $diagnostic = $this->diagnostic(44);
        $metrics = new CompletionMetrics(
            new TokenUsage(3, 5, 9, 1, 2, 4),
            InvocationCost::fromUsd('0.0123'),
            17,
            providerRequests: 2,
            usageComplete: false,
        );
        $response = new CompletionResponse(
            text: 'ignored',
            structured: ['content' => 'answer'],
            provider: 'provider-a',
            model: 'model-a',
            metadata: ['resolved_route' => ' route-a '],
            metrics: $metrics,
            diagnostic: $diagnostic,
        );

        $actual = $this->invokePrivate($this->pipe(), 'attemptMetrics', [$request, $response]);
        self::assertInstanceOf(AttemptMetrics::class, $actual);
        self::assertSame([
            'schema_version' => 1,
            'provider' => 'provider-a',
            'model' => 'model-a',
            'resolved_route' => ' route-a ',
            'model_tier' => 'policy',
            'tokens' => $metrics->tokens->toArray(),
            'cost_usd' => '0.0123',
            'latency_milliseconds' => 17,
            'provider_requests' => 2,
            'usage_present' => true,
            'usage_complete' => false,
            'prompt_characters' => strlen('Żółć') + strlen('abc'),
            'response_characters' => 44,
        ], $actual->toArray());

        $withoutUsage = $this->invokePrivate(
            $this->pipe(),
            'attemptMetrics',
            [$request, new CompletionResponse('plain', provider: 'p', model: 'm')],
        );
        self::assertInstanceOf(AttemptMetrics::class, $withoutUsage);
        self::assertSame('p:m', $withoutUsage->resolvedRoute);
        self::assertSame(TokenUsage::zero()->toArray(), $withoutUsage->tokens->toArray());
        self::assertSame(1, $withoutUsage->providerRequests);
        self::assertFalse($withoutUsage->usagePresent);
        self::assertFalse($withoutUsage->usageComplete);
        self::assertSame(strlen('plain'), $withoutUsage->responseCharacters);
    }

    public function test_failure_metrics_distinguish_unaccepted_unknown_and_measured_responses(): void
    {
        $request = $this->request();
        $notAccepted = new ProviderRequestException(
            'rejected',
            'Rejected.',
            false,
            ProviderRequestOutcome::NotAccepted,
        );
        self::assertNull($this->invokePrivate($this->pipe(), 'failureMetrics', [$request, $notAccepted]));

        $unknown = new ProviderRequestException(
            'invalid',
            'Invalid.',
            false,
            ProviderRequestOutcome::ResponseReceived,
        );
        $synthetic = $this->invokePrivate($this->pipe(), 'failureMetrics', [$request, $unknown]);
        self::assertInstanceOf(AttemptMetrics::class, $synthetic);
        self::assertSame('unknown', $synthetic->provider);
        self::assertSame('unknown', $synthetic->model);
        self::assertSame('unknown:unknown', $synthetic->resolvedRoute);
        self::assertSame('policy', $synthetic->modelTier);
        self::assertSame(1, $synthetic->providerRequests);
        self::assertFalse($synthetic->usagePresent);
        self::assertFalse($synthetic->usageComplete);
        self::assertSame(0, $synthetic->responseCharacters);

        $metrics = new CompletionMetrics(
            new TokenUsage(11, 7),
            InvocationCost::fromUsd('0.02'),
            33,
            providerRequests: 3,
        );
        $measured = new ProviderRequestException(
            'invalid',
            'Invalid.',
            false,
            ProviderRequestOutcome::ResponseReceived,
            metrics: $metrics,
            diagnostic: $this->diagnostic(71),
            provider: 'provider-b',
            model: 'model-b',
            resolvedRoute: 'provider-b:model-b:custom',
            modelTier: 'quality',
        );
        $actual = $this->invokePrivate($this->pipe(), 'failureMetrics', [$request, $measured]);
        self::assertInstanceOf(AttemptMetrics::class, $actual);
        self::assertSame('provider-b', $actual->provider);
        self::assertSame('model-b', $actual->model);
        self::assertSame('provider-b:model-b:custom', $actual->resolvedRoute);
        self::assertSame('quality', $actual->modelTier);
        self::assertSame($metrics->tokens->toArray(), $actual->tokens->toArray());
        self::assertSame('0.02', $actual->cost?->toUsdDecimal());
        self::assertSame(33, $actual->latencyMilliseconds);
        self::assertSame(3, $actual->providerRequests);
        self::assertTrue($actual->usagePresent);
        self::assertTrue($actual->usageComplete);
        self::assertSame(71, $actual->responseCharacters);
    }

    public function test_provider_identifier_precedence_is_exact(): void
    {
        $pipe = $this->pipe();
        $provided = new ProviderIdentifiers('gateway', 'request', 'generation', ProviderIdSource::Body);
        $withIdentifiers = new ProviderRequestException(
            'failed',
            'Failed.',
            false,
            ProviderRequestOutcome::ResponseReceived,
            requestId: 'legacy',
            identifiers: $provided,
        );
        self::assertSame($provided, $this->invokePrivate($pipe, 'identifiers', [$withIdentifiers]));

        $legacy = new ProviderRequestException(
            'failed',
            'Failed.',
            false,
            ProviderRequestOutcome::ResponseReceived,
            requestId: 'legacy',
        );
        $legacyIdentifiers = $this->invokePrivate($pipe, 'identifiers', [$legacy]);
        self::assertInstanceOf(ProviderIdentifiers::class, $legacyIdentifiers);
        self::assertNull($legacyIdentifiers->gatewayInvocationId);
        self::assertSame('legacy', $legacyIdentifiers->providerRequestId);
        self::assertNull($legacyIdentifiers->providerGenerationId);
        self::assertSame(ProviderIdSource::Header, $legacyIdentifiers->source);

        $unavailable = new ProviderRequestException(
            'failed',
            'Failed.',
            false,
            ProviderRequestOutcome::NotAccepted,
        );
        $unavailableIdentifiers = $this->invokePrivate($pipe, 'identifiers', [$unavailable]);
        self::assertInstanceOf(ProviderIdentifiers::class, $unavailableIdentifiers);
        self::assertSame(ProviderIdSource::Unavailable, $unavailableIdentifiers->source);
    }

    public function test_correlation_contains_exact_invocation_attempt_and_schema_identity(): void
    {
        $pipe = $this->pipe();
        $request = $this->request(ResponseContract::Candidate);
        $invocation = $this->invocation($request, attempts: 1, index: 4);
        $attempt = InvocationAttempt::start(
            InvocationAttemptId::fromString('attempt-7'),
            $invocation->id(),
            $invocation->runId(),
            7,
            'request-fingerprint',
            new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
        );
        $error = new ProviderRequestException(
            'failed',
            'Failed.',
            false,
            ProviderRequestOutcome::ResponseReceived,
        );

        $this->invokePrivate($pipe, 'correlate', [
            $error,
            $invocation,
            $attempt,
            $request,
            'exact_retry_decision',
        ]);

        $context = $error->context();
        self::assertSame('run-1', $context['run_id']);
        self::assertSame('step-1', $context['step_id']);
        self::assertSame('execution-1', $context['step_execution_id']);
        self::assertSame('invocation-1', $context['invocation_id']);
        self::assertSame(4, $context['invocation_index']);
        self::assertSame(5, $context['candidate_number']);
        self::assertSame('attempt-7', $context['attempt_id']);
        self::assertSame(7, $context['attempt_number']);
        self::assertSame('candidate', $context['contract']);
        self::assertSame(
            $this->application()->make(ResponseSchemaResolver::class)->fingerprint($request),
            $context['schema_fingerprint'],
        );
        self::assertSame('exact_retry_decision', $context['retry_decision']);

        $textError = new ProviderRequestException(
            'failed',
            'Failed.',
            false,
            ProviderRequestOutcome::ResponseReceived,
        );
        $textRequest = $this->request();
        $this->invokePrivate($pipe, 'correlate', [
            $textError,
            $this->invocation($textRequest),
            $attempt,
            $textRequest,
            'none',
        ]);
        self::assertNull($textError->context()['schema_fingerprint']);
    }

    public function test_retry_plans_are_bounded_and_use_only_distinct_routes(): void
    {
        $models = new ModelPolicyRegistry([
            new ModelPolicy(
                'policy',
                'primary',
                ['temperature' => 0.2],
                ['primary-duplicate', 'fallback', 'second-fallback'],
            ),
        ]);
        $routes = new class implements ProviderRouteResolverBase
        {
            public function identity(string $modelTier): string
            {
                return match ($modelTier) {
                    'primary', 'primary-duplicate' => 'provider:same',
                    default => 'provider:'.$modelTier,
                };
            }
        };
        $pipe = $this->pipe($models, $routes, maxSafeAttempts: 3, structuredAttempts: 4);
        $structured = new ProviderRequestException(
            'provider_response_invalid',
            'Invalid response.',
            false,
            ProviderRequestOutcome::ResponseReceived,
        );

        [$sameRoute, $sameDecision] = $this->retryPlan($pipe, $this->invocation(attempts: 1), $structured);
        self::assertInstanceOf(CompletionRequest::class, $sameRoute);
        self::assertSame('primary', $sameRoute->modelTier);
        self::assertSame(['temperature' => 0.2], $sameRoute->options);
        self::assertSame('structured_retry_same_route_scheduled', $sameDecision);

        [$fallback, $fallbackDecision] = $this->retryPlan($pipe, $this->invocation(attempts: 2), $structured);
        self::assertInstanceOf(CompletionRequest::class, $fallback);
        self::assertSame('fallback', $fallback->modelTier);
        self::assertSame('structured_retry_scheduled', $fallbackDecision);

        [$secondFallback, $secondDecision] = $this->retryPlan(
            $pipe,
            $this->invocation(attempts: 3),
            $structured,
        );
        self::assertInstanceOf(CompletionRequest::class, $secondFallback);
        self::assertSame('second-fallback', $secondFallback->modelTier);
        self::assertSame('structured_retry_scheduled', $secondDecision);

        [$exhausted, $exhaustedDecision] = $this->retryPlan(
            $pipe,
            $this->invocation(attempts: 4),
            $structured,
        );
        self::assertNull($exhausted);
        self::assertSame('structured_retry_exhausted', $exhaustedDecision);

        $requestLimit = new CompletionRequest(
            [new Message('user', 'Verifier prompt')],
            ResponseContract::Text,
            'verifier',
            'policy',
            structuredResponseAttempts: 2,
        );
        [$requestRetry, $requestDecision] = $this->retryPlan(
            $this->pipe($models, $routes, structuredAttempts: 1),
            $this->invocation($requestLimit, attempts: 1),
            $structured,
        );
        self::assertInstanceOf(CompletionRequest::class, $requestRetry);
        self::assertSame(2, $requestRetry->structuredResponseAttempts);
        self::assertSame('structured_retry_same_route_scheduled', $requestDecision);
        self::assertSame(
            [null, 'structured_retry_exhausted'],
            $this->retryPlan(
                $pipe,
                $this->invocation($requestLimit, attempts: 2),
                $structured,
            ),
        );

        $indeterminate = new ProviderRequestException(
            'unknown',
            'Unknown.',
            true,
            ProviderRequestOutcome::Indeterminate,
        );
        self::assertSame(
            [null, 'manual_reconciliation_required'],
            $this->retryPlan($pipe, $this->invocation(attempts: 1), $indeterminate),
        );

        $safe = new ProviderRequestException(
            'temporary',
            'Temporary.',
            true,
            ProviderRequestOutcome::NotAccepted,
        );
        [$safeRetry, $safeDecision] = $this->retryPlan($pipe, $this->invocation(attempts: 1), $safe);
        self::assertInstanceOf(CompletionRequest::class, $safeRetry);
        self::assertSame('primary-duplicate', $safeRetry->modelTier);
        self::assertSame('safe_retry_scheduled', $safeDecision);
        self::assertSame(
            [null, 'safe_retry_exhausted'],
            $this->retryPlan($pipe, $this->invocation(attempts: 3), $safe),
        );

        $notRetryable = new ProviderRequestException(
            'permanent',
            'Permanent.',
            false,
            ProviderRequestOutcome::NotAccepted,
        );
        self::assertSame(
            [null, 'retry_not_allowed'],
            $this->retryPlan($pipe, $this->invocation(attempts: 1), $notRetryable),
        );

        $disabled = $this->pipe(
            $models,
            $routes,
            structuredAttempts: 4,
            structuredStrategy: 'disabled',
        );
        self::assertSame(
            [null, 'structured_retry_disabled'],
            $this->retryPlan($disabled, $this->invocation(attempts: 1), $structured),
        );

        $missingPolicy = new CompletionRequest(
            [new Message('user', 'Prompt')],
            ResponseContract::Text,
            'purpose',
            'missing-policy',
        );
        self::assertSame(
            [null, 'structured_fallback_unavailable'],
            $this->retryPlan(
                $pipe,
                $this->invocation($missingPolicy, attempts: 1),
                $structured,
            ),
        );
        self::assertSame(
            $missingPolicy,
            $this->invokePrivate($pipe, 'routed', [$missingPolicy, 2, null]),
        );
    }

    /** @return array{?CompletionRequest, string} */
    private function retryPlan(
        ExecuteInvocationPipe $pipe,
        LlmInvocation $invocation,
        ProviderRequestException $error,
    ): array {
        $result = $this->invokePrivate($pipe, 'retryPlan', [$invocation, $error]);
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertTrue($result[0] === null || $result[0] instanceof CompletionRequest);
        self::assertIsString($result[1]);

        return [$result[0], $result[1]];
    }

    private function pipe(
        ?ModelPolicyRegistry $models = null,
        ?ProviderRouteResolverBase $routes = null,
        int $maxSafeAttempts = 3,
        int $structuredAttempts = 1,
        string $structuredStrategy = 'same_route_then_fallback',
    ): ExecuteInvocationPipe {
        return new ExecuteInvocationPipe(
            $this->application()->make(ExecutionRepositoryBase::class),
            $this->application()->make(RunRepositoryBase::class),
            $this->application()->make(TransactionBase::class),
            $this->application()->make(ClockBase::class),
            $this->application()->make(GatewayBase::class),
            $this->application()->make(EventOutboxBase::class),
            $this->application()->make(ExecutionBackendBase::class),
            $models ?? $this->application()->make(ModelPolicyRegistry::class),
            $routes ?? $this->application()->make(ProviderRouteResolverBase::class),
            $this->application()->make(ResourceBudgetGuard::class),
            $this->application()->make(DomainEventRecorder::class),
            $this->application()->make(IdGeneratorBase::class),
            $this->application()->make(CompletionResponseValidator::class),
            $this->application()->make(ResponseSchemaResolver::class),
            300,
            $maxSafeAttempts,
            $structuredAttempts,
            $structuredStrategy,
        );
    }

    private function request(ResponseContract $contract = ResponseContract::Text): CompletionRequest
    {
        return new CompletionRequest(
            [new Message('system', 'System'), new Message('user', 'Prompt')],
            $contract,
            'purpose',
            'policy',
        );
    }

    private function invocation(
        ?CompletionRequest $request = null,
        int $attempts = 0,
        int $index = 0,
    ): LlmInvocation {
        return LlmInvocation::restore(
            InvocationId::fromString('invocation-1'),
            StepExecutionId::fromString('execution-1'),
            RunId::fromString('run-1'),
            StepId::fromString('step-1'),
            $index,
            $request ?? $this->request(),
            InvocationStatus::Pending,
            $attempts,
            0,
            null,
            null,
            null,
        );
    }

    private function diagnostic(int $responseBytes): StructuredResponseDiagnostic
    {
        return new StructuredResponseDiagnostic(
            StructuredResponseStage::SchemaValidation,
            ResponseContract::Candidate,
            str_repeat('a', 64),
            true,
            $responseBytes,
            str_repeat('b', 64),
            StructuredDecodeStatus::Object,
            'object',
            '$.content',
            'required',
            'stop',
            true,
            false,
        );
    }

    /** @param list<mixed> $arguments */
    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
}
