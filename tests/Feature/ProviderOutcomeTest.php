<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Exceptions;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Interface\ExecutionRepositoryBase;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Infrastructure\Llm\LaravelAiFailureClassifier;
use Rick\Laravel\Infrastructure\Persistence\DatabaseRow;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;
use RuntimeException;

final class ProviderOutcomeTest extends TestCase
{
    public function test_http_client_rejection_fails_safely_without_indeterminate_recovery_or_body_leakage(): void
    {
        $secret = 'provider-400-secret-body';
        $this->application()->instance(GatewayBase::class, new class($secret) implements GatewayBase
        {
            public function __construct(private readonly string $secret) {}

            public function complete(CompletionRequest $request): CompletionResponse
            {
                $failure = new RequestException(new Response(new PsrResponse(
                    400,
                    ['X-Request-Id' => 'provider-request-400'],
                    $this->secret,
                )));

                throw (new LaravelAiFailureClassifier)->classify($failure);
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('provider-client-rejection')
            ->resolve('Generate safely', 'A valid candidate is returned')
            ->generate('draft', candidates: 1)
            ->build();

        $run = $rick->run($workflow);
        self::assertSame('failed', $run->status->value);

        $database = $this->application()->make(ConnectionInterface::class);
        $invocation = DatabaseRow::from(
            $database->table('rick_llm_invocations')->first()
                ?? throw new RuntimeException('Missing persisted invocation.'),
        );
        $attempt = DatabaseRow::from(
            $database->table('rick_invocation_attempts')->first()
                ?? throw new RuntimeException('Missing persisted invocation attempt.'),
        );

        self::assertSame('failed', $invocation->string('status'));
        self::assertSame('failed', $attempt->string('status'));
        self::assertSame('provider_request_rejected', $attempt->string('error_code'));
        self::assertSame('provider-request-400', $attempt->string('provider_request_id'));
        self::assertSame('header', $attempt->string('provider_id_source'));
        self::assertSame('not_accepted', $attempt->string('provider_request_outcome'));
        self::assertSame('4xx', $attempt->string('http_status_class'));
        self::assertSame(
            0,
            $database->table('rick_outbox')
                ->where('event_type', 'invocation.recovery_required')
                ->count(),
        );

        foreach (['rick_llm_invocations', 'rick_invocation_attempts', 'rick_outbox'] as $table) {
            foreach ($database->table($table)->get() as $row) {
                self::assertStringNotContainsString(
                    $secret,
                    json_encode($row, JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    public function test_invalid_paid_response_is_not_retried_but_preserves_usage_without_payload_leakage(): void
    {
        $secret = 'paid-response-secret-marker';
        Exceptions::fake([ProviderRequestException::class]);
        $this->application()->instance(GatewayBase::class, new class($secret) implements GatewayBase
        {
            public function __construct(private readonly string $secret) {}

            public function complete(CompletionRequest $request): CompletionResponse
            {
                return new CompletionResponse(
                    structured: ['wrong' => $this->secret],
                    provider: 'fake-provider',
                    model: 'fake-model',
                    metadata: ['request_id' => 'provider-request-1'],
                    metrics: new CompletionMetrics(
                        new TokenUsage(17, 5),
                        InvocationCost::fromUsd('0.0017'),
                        latencyMilliseconds: 42,
                    ),
                );
            }
        });
        $rick = $this->application()->make(Rick::class);
        $workflow = $rick->workflow('invalid-paid-response')
            ->resolve('Generate safely', 'A valid candidate is returned')
            ->generate('draft', candidates: 1)
            ->build();

        $run = $rick->run($workflow);
        self::assertSame('failed', $run->status->value);

        $database = $this->application()->make(ConnectionInterface::class);
        $rowObject = $database->table('rick_llm_invocations')->first()
            ?? throw new RuntimeException('Missing persisted invocation.');
        $row = DatabaseRow::from($rowObject);
        $invocation = $this->application()->make(ExecutionRepositoryBase::class)->getInvocation(
            InvocationId::fromString($row->string('id')),
        );

        self::assertSame(InvocationStatus::Failed, $invocation->status());
        self::assertSame(1, $invocation->attempts());
        self::assertNull($invocation->response());
        $metrics = $invocation->metrics();
        self::assertNotNull($metrics);
        self::assertSame(22, $metrics->tokens->totalTokens);
        self::assertSame('0.0017', $metrics->cost?->toUsdDecimal());
        self::assertSame('provider_response_invalid', $row->string('error_code'));
        self::assertNull($row->nullableString('response_payload'));
        self::assertNotNull($row->nullableString('metrics_payload'));
        $attempt = DatabaseRow::from(
            $database->table('rick_invocation_attempts')->first()
                ?? throw new RuntimeException('Missing persisted invocation attempt.'),
        );
        self::assertSame('response_received', $attempt->string('provider_request_outcome'));
        self::assertSame('provider-request-1', $attempt->string('provider_request_id'));
        self::assertSame('fake-provider', $attempt->string('provider'));
        self::assertSame('fake-model', $attempt->string('model'));
        self::assertNotNull($attempt->nullableString('diagnostic_payload'));
        $publicMetrics = $rick->metrics($run->id);
        self::assertSame(1, $publicMetrics->totals->failedCalls);
        self::assertSame(1, $publicMetrics->totals->measuredAttempts);
        self::assertSame(22, $publicMetrics->totals->tokens->totalTokens);
        self::assertSame('0.0017', $publicMetrics->totals->cost->toUsdDecimal());
        $attemptDetails = $publicMetrics->invocations[0]->attemptDetails[0];
        self::assertNotNull($attemptDetails->diagnostic);
        self::assertSame('schema_validation', $attemptDetails->diagnostic->stage->value);
        self::assertSame('additionalProperties', $attemptDetails->diagnostic->validationKeyword);

        foreach ([
            'request_payload',
            'metrics_payload',
            'error_code',
            'error_message',
        ] as $column) {
            $value = $row->nullableString($column);
            if ($value !== null) {
                self::assertStringNotContainsString($secret, $value);
            }
        }
        foreach (['metrics_payload', 'diagnostic_payload', 'error_code', 'error_message'] as $column) {
            $value = $attempt->nullableString($column);
            if ($value !== null) {
                self::assertStringNotContainsString($secret, $value);
            }
        }
        $eventPayloads = $database->table('rick_outbox')->pluck('payload')
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->values()
            ->all();
        foreach ($eventPayloads as $payload) {
            self::assertStringNotContainsString($secret, $payload);
        }
        self::assertStringNotContainsString(
            $secret,
            json_encode($rick->timeline($run->id), JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            $secret,
            json_encode($publicMetrics, JSON_THROW_ON_ERROR),
        );
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static function (ProviderRequestException $error) use ($secret): bool {
            $context = $error->context();
            foreach ([
                'run_id',
                'step_id',
                'step_execution_id',
                'invocation_id',
                'invocation_index',
                'candidate_number',
                'attempt_id',
                'attempt_number',
                'contract',
                'schema_fingerprint',
                'error_code',
                'validation_stage',
                'validation_path',
                'validation_keyword',
                'provider',
                'model',
                'retry_decision',
            ] as $key) {
                self::assertArrayHasKey($key, $context);
            }
            self::assertSame('provider_response_invalid', $context['error_code']);
            self::assertSame('schema_validation', $context['validation_stage']);
            self::assertStringNotContainsString(
                $secret,
                json_encode($context, JSON_THROW_ON_ERROR),
            );

            return true;
        });
    }
}
