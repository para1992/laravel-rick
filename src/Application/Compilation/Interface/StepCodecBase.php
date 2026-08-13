<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Interface;

use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

interface StepCodecBase
{
    public function type(): StepType;

    public function version(): int;

    /** @return array<string, mixed> */
    public function encode(StepBase $step): array;

    /** @param array<string, mixed> $payload */
    public function decode(StepId $id, array $payload): StepBase;
}
