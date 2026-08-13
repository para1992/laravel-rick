<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality\Result;

enum RepairDecision: string
{
    case Accept = 'accept';
    case Repair = 'repair';
    case Fail = 'fail';
}
