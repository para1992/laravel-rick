<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\Plan;

use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;

final readonly class AwaitingExternalInputPlan implements StepPlanBase
{
    /** @param array<string, mixed>|null $schema */
    public function __construct(
        public string $key,
        public string $prompt,
        public ?array $schema = null,
    ) {}
}
