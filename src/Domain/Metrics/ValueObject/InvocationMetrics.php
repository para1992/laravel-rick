<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use JsonSerializable;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final readonly class InvocationMetrics implements JsonSerializable
{
    /** @param list<InvocationAttemptMetrics> $attemptDetails */
    public function __construct(
        public InvocationId $id,
        public StepId $stepId,
        public int $index,
        public InvocationStatus $status,
        public int $attempts,
        public string $purpose,
        public string $modelTier,
        public ?string $provider,
        public ?string $model,
        public ?TokenUsage $tokens,
        public ?InvocationCost $cost,
        public ?int $latencyMilliseconds,
        public bool $usageComplete,
        public bool $usagePresent,
        public int $providerRequests,
        public array $attemptDetails,
        public ?RunId $sourceRunId = null,
        public ?InvocationId $sourceInvocationId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'schema_version' => 2,
            'id' => $this->id->toString(),
            'step_id' => $this->stepId->toString(),
            'index' => $this->index,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'purpose' => $this->purpose,
            'model_tier' => $this->modelTier,
            'provider' => $this->provider,
            'model' => $this->model,
            'tokens' => $this->tokens?->toArray(),
            'cost_usd' => $this->cost?->toUsdDecimal(),
            'latency_milliseconds' => $this->latencyMilliseconds,
            'provider_requests' => $this->providerRequests,
            'usage_present' => $this->usagePresent,
            'usage_complete' => $this->usageComplete,
            'attempt_details' => array_map(
                static fn (InvocationAttemptMetrics $attempt): array => $attempt->toArray(),
                $this->attemptDetails,
            ),
        ];

        if ($this->sourceRunId !== null && $this->sourceInvocationId !== null) {
            $data['reused'] = $this->status === InvocationStatus::Succeeded && $this->attempts === 0;
            $data['source_run_id'] = $this->sourceRunId->toString();
            $data['source_invocation_id'] = $this->sourceInvocationId->toString();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
