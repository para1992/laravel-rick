<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution;

enum UnfoldPhase: string
{
    case Explode = 'explode';
    case Generate = 'generate';
    case Judge = 'judge';
    case Complete = 'complete';
}
