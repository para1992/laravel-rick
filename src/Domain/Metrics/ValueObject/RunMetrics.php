<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Metrics\ValueObject;

use JsonSerializable;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

final readonly class RunMetrics implements JsonSerializable
{
    /**
     * @param  array<string, MetricTotals>  $byPurpose
     * @param  array<string, MetricTotals>  $byModelTier
     * @param  array<string, MetricTotals>  $byModel
     * @param  array<string, MetricTotals>  $byStep
     * @param  list<InvocationMetrics>  $invocations
     */
    public function __construct(
        public RunId $runId,
        public RunStatus $status,
        public int $runVersion,
        public int $callsUsed,
        public int $callLimit,
        public MetricTotals $totals,
        public array $byPurpose,
        public array $byModelTier,
        public array $byModel,
        public array $byStep,
        public array $invocations,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 2,
            'run_id' => $this->runId->toString(),
            'status' => $this->status->value,
            'run_version' => $this->runVersion,
            'calls_used' => $this->callsUsed,
            'call_limit' => $this->callLimit,
            'totals' => $this->totals->toArray(),
            'by_purpose' => self::groups($this->byPurpose),
            'by_model_tier' => self::groups($this->byModelTier),
            'by_model' => self::groups($this->byModel),
            'by_step' => self::groups($this->byStep),
            'invocations' => array_map(
                static fn (InvocationMetrics $metrics): array => $metrics->toArray(),
                $this->invocations,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, MetricTotals>  $groups
     * @return array<string, array<string, mixed>>
     */
    private static function groups(array $groups): array
    {
        return array_map(
            static fn (MetricTotals $metrics): array => $metrics->toArray(),
            $groups,
        );
    }
}
