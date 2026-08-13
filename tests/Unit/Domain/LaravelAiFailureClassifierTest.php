<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Infrastructure\Llm\LaravelAiFailureClassifier;
use RuntimeException;

final class LaravelAiFailureClassifierTest extends TestCase
{
    public function test_transport_and_unknown_failures_require_manual_reconciliation(): void
    {
        $classifier = new LaravelAiFailureClassifier;

        foreach ([
            new ConnectionException('Connection dropped.'),
            new RuntimeException('Unknown failure after dispatch.'),
        ] as $failure) {
            $classified = $classifier->classify($failure);

            self::assertSame(ProviderRequestOutcome::Indeterminate, $classified->outcome);
            self::assertFalse($classified->retryable);
            self::assertSame($failure, $classified->getPrevious());
        }
    }

    public function test_explicit_provider_rejections_are_safe_and_keep_retryability(): void
    {
        $classifier = new LaravelAiFailureClassifier;

        foreach ([
            RateLimitedException::forProvider('fake'),
            ProviderOverloadedException::forProvider('fake'),
        ] as $failure) {
            $classified = $classifier->classify($failure);

            self::assertSame(ProviderRequestOutcome::NotAccepted, $classified->outcome);
            self::assertTrue($classified->retryable);
        }

        $credits = $classifier->classify(
            InsufficientCreditsException::forProvider('fake'),
        );
        self::assertSame(ProviderRequestOutcome::NotAccepted, $credits->outcome);
        self::assertFalse($credits->retryable);
    }

    public function test_client_errors_are_safe_provider_rejections_without_response_body_leakage(): void
    {
        $classifier = new LaravelAiFailureClassifier;

        foreach ([400, 401, 403, 404, 422] as $status) {
            $failure = self::requestFailure(
                $status,
                '{"secret":"must-not-be-persisted"}',
                ['X-Request-Id' => 'request-'.$status],
            );
            $classified = $classifier->classify($failure);

            self::assertSame(ProviderRequestOutcome::NotAccepted, $classified->outcome);
            self::assertFalse($classified->retryable);
            self::assertSame('provider_request_rejected', $classified->safeCode);
            self::assertSame('request-'.$status, $classified->requestId);
            self::assertSame('4xx', $classified->httpStatusClass);
            self::assertStringNotContainsString('must-not-be-persisted', $classified->safeMessage);
            self::assertStringNotContainsString('must-not-be-persisted', $classified->getMessage());
        }
    }

    public function test_transient_client_errors_remain_safe_retryable_rejections(): void
    {
        $classifier = new LaravelAiFailureClassifier;

        foreach ([408, 409, 425, 429] as $status) {
            $classified = $classifier->classify(self::requestFailure($status));

            self::assertSame(ProviderRequestOutcome::NotAccepted, $classified->outcome);
            self::assertTrue($classified->retryable);
            self::assertSame('provider_request_deferred', $classified->safeCode);
            self::assertSame('4xx', $classified->httpStatusClass);
        }
    }

    public function test_server_errors_keep_an_indeterminate_outcome_with_safe_metadata_only(): void
    {
        $classified = (new LaravelAiFailureClassifier)->classify(self::requestFailure(
            503,
            'provider-secret-response',
            ['X-OpenAI-Request-Id' => 'provider-request-503'],
        ));

        self::assertSame(ProviderRequestOutcome::Indeterminate, $classified->outcome);
        self::assertFalse($classified->retryable);
        self::assertSame('provider_outcome_indeterminate', $classified->safeCode);
        self::assertSame('provider-request-503', $classified->requestId);
        self::assertSame('5xx', $classified->httpStatusClass);
        self::assertStringNotContainsString('provider-secret-response', $classified->getMessage());
    }

    public function test_unsafe_request_id_headers_are_not_persisted(): void
    {
        $classified = (new LaravelAiFailureClassifier)->classify(self::requestFailure(
            400,
            '{}',
            ['X-Request-Id' => 'unsafe request id'],
        ));

        self::assertNull($classified->requestId);
        self::assertSame('4xx', $classified->httpStatusClass);
    }

    /** @param array<string, string> $headers */
    private static function requestFailure(
        int $status,
        string $body = '{}',
        array $headers = [],
    ): RequestException {
        return new RequestException(new Response(new PsrResponse($status, $headers, $body)));
    }
}
