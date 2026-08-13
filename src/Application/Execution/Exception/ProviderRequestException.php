<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use RuntimeException;
use Throwable;

final class ProviderRequestException extends RuntimeException
{
    /** @var array<string, bool|int|float|string|null> */
    private array $safeContext = [];

    public function __construct(
        public readonly string $safeCode,
        public readonly string $safeMessage,
        public readonly bool $retryable,
        public readonly ProviderRequestOutcome $outcome,
        public readonly ?string $requestId = null,
        public readonly ?CompletionMetrics $metrics = null,
        ?Throwable $previous = null,
        public readonly ?string $httpStatusClass = null,
        public readonly ?ProviderIdentifiers $identifiers = null,
        public readonly ?StructuredResponseDiagnostic $diagnostic = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $resolvedRoute = null,
        public readonly ?string $modelTier = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $safeCode) !== 1) {
            throw new InvalidArgumentException('Provider failure code is invalid.');
        }
        if (trim($safeMessage) === '') {
            throw new InvalidArgumentException('Provider failure message must not be empty.');
        }
        if ($httpStatusClass !== null && preg_match('/^[1-5]xx$/', $httpStatusClass) !== 1) {
            throw new InvalidArgumentException('Provider HTTP status class is invalid.');
        }

        parent::__construct($safeMessage, previous: $previous);
    }

    /** @param array<string, bool|int|float|string|null> $context */
    public function correlate(array $context): self
    {
        $this->safeContext = $context;

        return $this;
    }

    /** @return array<string, bool|int|float|string|null> */
    public function context(): array
    {
        $diagnostic = $this->diagnostic;
        $diagnosticContext = $diagnostic === null ? [] : [
            'validation_stage' => $diagnostic->stage->value,
            'decode_status' => $diagnostic->decodeStatus->value,
            'decoded_root_type' => $diagnostic->decodedRootType,
            'validation_path' => $diagnostic->validationPath,
            'validation_keyword' => $diagnostic->validationKeyword,
            'response_present' => $diagnostic->responsePresent,
            'response_bytes' => $diagnostic->responseBytes,
            'response_fingerprint' => $diagnostic->responseFingerprint,
            'finish_reason' => $diagnostic->finishReason,
            'usage_present' => $diagnostic->usagePresent,
            'usage_complete' => $diagnostic->usageComplete,
        ];

        return [
            'error_code' => $this->safeCode,
            'provider_outcome' => $this->outcome->value,
            'provider' => $this->provider,
            'model' => $this->model,
            'gateway_invocation_id' => $this->identifiers?->gatewayInvocationId,
            'provider_request_id' => $this->identifiers?->providerRequestId,
            'provider_generation_id' => $this->identifiers?->providerGenerationId,
            'provider_id_source' => $this->identifiers?->source->value,
        ] + $diagnosticContext + $this->safeContext;
    }
}
