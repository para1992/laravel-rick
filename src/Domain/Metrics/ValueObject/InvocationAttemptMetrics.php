<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use DateTimeImmutable;
use JsonSerializable;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdentifiers;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseDiagnostic;

final readonly class InvocationAttemptMetrics implements JsonSerializable
{
    public function __construct(
        public InvocationAttemptId $id,
        public int $number,
        public InvocationAttemptStatus $status,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?ProviderIdentifiers $identifiers,
        public ?AttemptMetrics $metrics,
        public ?StructuredResponseDiagnostic $diagnostic,
        public ?ProviderRequestOutcome $outcome,
        public ?string $errorCode,
        public ?string $httpStatusClass,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $metrics = $this->metrics;

        return [
            'schema_version' => 1,
            'id' => $this->id->toString(),
            'number' => $this->number,
            'status' => $this->status->value,
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
            'gateway_invocation_id' => $this->identifiers?->gatewayInvocationId,
            'provider_request_id' => $this->identifiers?->providerRequestId,
            'provider_generation_id' => $this->identifiers?->providerGenerationId,
            'provider_id_source' => $this->identifiers?->source->value,
            'provider_request_outcome' => $this->outcome?->value,
            'provider' => $this->metrics?->provider,
            'model' => $this->metrics?->model,
            'resolved_route' => $this->metrics?->resolvedRoute,
            'model_tier' => $this->metrics?->modelTier,
            'tokens' => $this->metrics?->tokens->toArray(),
            'cost_usd' => $this->metrics?->cost?->toUsdDecimal(),
            'latency_milliseconds' => $this->metrics?->latencyMilliseconds,
            'provider_requests' => $metrics === null ? 0 : $metrics->providerRequests,
            'usage_present' => $metrics !== null && $metrics->usagePresent,
            'usage_complete' => $metrics !== null && $metrics->usageComplete,
            'prompt_characters' => $metrics === null ? 0 : $metrics->promptCharacters,
            'response_characters' => $metrics === null ? 0 : $metrics->responseCharacters,
            'error_code' => $this->errorCode,
            'http_status_class' => $this->httpStatusClass,
            'diagnostic' => $this->diagnostic?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
