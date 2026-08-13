<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use RuntimeException;

final class ProviderRequestExceptionTest extends TestCase
{
    public function test_it_preserves_every_failure_field_and_builds_an_exact_safe_context(): void
    {
        $previous = new RuntimeException('Private transport detail');
        $metrics = new CompletionMetrics(new TokenUsage(7, 3));
        $identifiers = new ProviderIdentifiers(
            'gateway-1',
            'request-2',
            'generation-3',
            ProviderIdSource::Header,
        );
        $diagnostic = new StructuredResponseDiagnostic(
            StructuredResponseStage::SchemaValidation,
            ResponseContract::Candidate,
            str_repeat('a', 64),
            true,
            123,
            str_repeat('b', 64),
            StructuredDecodeStatus::Object,
            'object',
            '$.content',
            'type',
            'stop',
            true,
            false,
            'retry_same_route',
        );
        $exception = new ProviderRequestException(
            'provider_response_invalid',
            'The provider response was invalid.',
            true,
            ProviderRequestOutcome::ResponseReceived,
            'legacy-request',
            $metrics,
            $previous,
            '4xx',
            $identifiers,
            $diagnostic,
            'openrouter',
            'model-x',
            'openrouter:model-x',
            'medium',
        );

        self::assertSame('provider_response_invalid', $exception->safeCode);
        self::assertSame('The provider response was invalid.', $exception->safeMessage);
        self::assertSame($exception->safeMessage, $exception->getMessage());
        self::assertTrue($exception->retryable);
        self::assertSame(ProviderRequestOutcome::ResponseReceived, $exception->outcome);
        self::assertSame('legacy-request', $exception->requestId);
        self::assertSame($metrics, $exception->metrics);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame('4xx', $exception->httpStatusClass);
        self::assertSame($identifiers, $exception->identifiers);
        self::assertSame($diagnostic, $exception->diagnostic);
        self::assertSame('openrouter', $exception->provider);
        self::assertSame('model-x', $exception->model);
        self::assertSame('openrouter:model-x', $exception->resolvedRoute);
        self::assertSame('medium', $exception->modelTier);
        self::assertSame($exception, $exception->correlate([
            'run_id' => 'run-1',
            'attempt_number' => 2,
            'error_code' => 'forged_code',
            'provider_outcome' => 'forged_outcome',
            'provider_request_id' => 'forged-id',
            'validation_stage' => 'forged-stage',
        ]));
        self::assertSame([
            'error_code' => 'provider_response_invalid',
            'provider_outcome' => 'response_received',
            'provider' => 'openrouter',
            'model' => 'model-x',
            'gateway_invocation_id' => 'gateway-1',
            'provider_request_id' => 'request-2',
            'provider_generation_id' => 'generation-3',
            'provider_id_source' => 'header',
            'validation_stage' => 'schema_validation',
            'decode_status' => 'object',
            'decoded_root_type' => 'object',
            'validation_path' => '$.content',
            'validation_keyword' => 'type',
            'response_present' => true,
            'response_bytes' => 123,
            'response_fingerprint' => str_repeat('b', 64),
            'finish_reason' => 'stop',
            'usage_present' => true,
            'usage_complete' => false,
            'run_id' => 'run-1',
            'attempt_number' => 2,
        ], $exception->context());
    }

    public function test_context_without_identifiers_or_diagnostic_is_explicitly_null(): void
    {
        $exception = new ProviderRequestException(
            'transport_failed',
            'Transport failed.',
            false,
            ProviderRequestOutcome::Indeterminate,
        );

        self::assertSame([
            'error_code' => 'transport_failed',
            'provider_outcome' => 'indeterminate',
            'provider' => null,
            'model' => null,
            'gateway_invocation_id' => null,
            'provider_request_id' => null,
            'provider_generation_id' => null,
            'provider_id_source' => null,
        ], $exception->context());
    }

    #[DataProvider('invalidFailures')]
    public function test_constructor_rejects_each_unsafe_field(
        string $code,
        string $message,
        ?string $statusClass,
        string $expectedMessage,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new ProviderRequestException(
            $code,
            $message,
            false,
            ProviderRequestOutcome::NotAccepted,
            httpStatusClass: $statusClass,
        );
    }

    /** @return iterable<string, array{string, string, ?string, string}> */
    public static function invalidFailures(): iterable
    {
        yield 'empty code' => ['', 'Failure', null, 'Provider failure code is invalid.'];
        yield 'uppercase code' => ['Provider_failure', 'Failure', null, 'Provider failure code is invalid.'];
        yield 'code starts with digit' => ['1provider', 'Failure', null, 'Provider failure code is invalid.'];
        yield 'code contains dash' => ['provider-failure', 'Failure', null, 'Provider failure code is invalid.'];
        yield 'code exceeds limit' => ['a'.str_repeat('b', 64), 'Failure', null, 'Provider failure code is invalid.'];
        yield 'blank message' => ['provider_failure', " \n\t", null, 'Provider failure message must not be empty.'];
        yield 'invalid status digit' => ['provider_failure', 'Failure', '6xx', 'Provider HTTP status class is invalid.'];
        yield 'invalid exact status' => ['provider_failure', 'Failure', '400', 'Provider HTTP status class is invalid.'];
    }
}
