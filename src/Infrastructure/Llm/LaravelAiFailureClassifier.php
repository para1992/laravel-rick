<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Llm;

use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use LogicException;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Throwable;

final readonly class LaravelAiFailureClassifier
{
    public function classify(Throwable $failure): ProviderRequestException
    {
        if ($failure instanceof ProviderRequestException) {
            return $failure;
        }

        $http = self::httpFailure($failure);

        if ($failure instanceof RateLimitedException || $failure instanceof ProviderOverloadedException) {
            return new ProviderRequestException(
                'provider_throttled',
                'The provider temporarily rejected the request.',
                retryable: true,
                outcome: ProviderRequestOutcome::NotAccepted,
                requestId: self::requestId($http),
                httpStatusClass: self::statusClass($http),
                previous: $failure,
            );
        }

        if (
            $failure instanceof InsufficientCreditsException
            || $failure instanceof InvalidArgumentException
            || $failure instanceof LogicException
        ) {
            return new ProviderRequestException(
                $failure instanceof InsufficientCreditsException
                    ? 'provider_credits_exhausted'
                    : 'provider_request_rejected',
                'The provider did not accept the request.',
                retryable: false,
                outcome: ProviderRequestOutcome::NotAccepted,
                requestId: self::requestId($http),
                httpStatusClass: self::statusClass($http),
                previous: $failure,
            );
        }

        if ($http !== null && $http->response->status() >= 400 && $http->response->status() < 500) {
            $status = $http->response->status();
            $retryable = in_array($status, [408, 409, 425, 429], true);

            return new ProviderRequestException(
                $retryable ? 'provider_request_deferred' : 'provider_request_rejected',
                $retryable
                    ? 'The provider temporarily rejected the request.'
                    : 'The provider did not accept the request.',
                retryable: $retryable,
                outcome: ProviderRequestOutcome::NotAccepted,
                requestId: self::requestId($http),
                httpStatusClass: self::statusClass($http),
                previous: $failure,
            );
        }

        return new ProviderRequestException(
            'provider_outcome_indeterminate',
            'The provider request outcome is unknown; operator reconciliation is required.',
            retryable: false,
            outcome: ProviderRequestOutcome::Indeterminate,
            requestId: self::requestId($http),
            httpStatusClass: self::statusClass($http),
            previous: $failure,
        );
    }

    public function preflight(Throwable $failure): ProviderRequestException
    {
        return new ProviderRequestException(
            'provider_request_preflight_failed',
            'The provider request failed before transport accepted it.',
            retryable: false,
            outcome: ProviderRequestOutcome::NotAccepted,
            previous: $failure,
        );
    }

    private static function httpFailure(Throwable $failure): ?RequestException
    {
        for ($depth = 0; $depth < 8; $depth++) {
            if ($failure instanceof RequestException && $failure->response !== null) {
                return $failure;
            }
            $previous = $failure->getPrevious();
            if (! $previous instanceof Throwable) {
                return null;
            }
            $failure = $previous;
        }

        return null;
    }

    private static function statusClass(?RequestException $failure): ?string
    {
        return $failure === null
            ? null
            : intdiv($failure->response->status(), 100).'xx';
    }

    private static function requestId(?RequestException $failure): ?string
    {
        if ($failure === null) {
            return null;
        }

        foreach (['x-request-id', 'x-openai-request-id', 'request-id'] as $header) {
            $value = trim($failure->response->header($header));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+=-]{0,127}$/D', $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
