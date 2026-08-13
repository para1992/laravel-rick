<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Interface;

use Rick\Laravel\Domain\Run\DeliverySnapshot;
use Rick\Laravel\Domain\Run\RunPage;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\RunTimeline;
use Rick\Laravel\Domain\Run\ValueObject\RunId;

interface ExecutionReadModelBase
{
    public function runs(?string $cursor, ?RunStatus $status, int $limit): RunPage;

    public function timeline(RunId $runId, int $afterVersion): RunTimeline;

    public function delivery(RunId $runId): DeliverySnapshot;
}
